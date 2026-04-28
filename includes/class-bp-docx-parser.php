<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Parses a .docx file into structured data.
 * A .docx is a ZIP containing word/document.xml — no Composer needed.
 *
 * Returns:
 *   [
 *     'title'    => string,
 *     'sections' => [
 *       [ 'heading' => string, 'content' => string (HTML) ],
 *       ...
 *     ]
 *   ]
 */
class BP_Docx_Parser {

    /** @var array<string,string> rId => target URL (from word/_rels/document.xml.rels) */
    private static array $rels = [];

    /** @var array<string,string> numId => first-level numFmt ('bullet','decimal','lowerLetter',...) */
    private static array $num_formats = [];

    public static function parse( string $file_path ): array {
        if ( ! file_exists( $file_path ) ) {
            throw new \RuntimeException( "File not found: {$file_path}" );
        }

        $files = self::extract_files( $file_path );
        if ( ! $files['document'] ) {
            throw new \RuntimeException( "Could not read document.xml from docx." );
        }

        self::$rels        = self::parse_rels( $files['rels'] );
        self::$num_formats = self::parse_numbering( $files['numbering'] );

        return self::parse_xml( $files['document'] );
    }

    // ── Private ──────────────────────────────────────────────────────────

    private static function extract_files( string $path ): array {
        $out = [ 'document' => null, 'rels' => null, 'numbering' => null ];
        $zip = new \ZipArchive();
        if ( $zip->open( $path ) !== true ) return $out;
        $out['document']  = $zip->getFromName( 'word/document.xml' ) ?: null;
        $out['rels']      = $zip->getFromName( 'word/_rels/document.xml.rels' ) ?: null;
        $out['numbering'] = $zip->getFromName( 'word/numbering.xml' ) ?: null;
        $zip->close();
        return $out;
    }

    private static function parse_rels( ?string $xml ): array {
        if ( ! $xml ) return [];
        $sx = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR );
        if ( ! ( $sx instanceof \SimpleXMLElement ) ) return [];
        $map = [];
        foreach ( $sx->Relationship as $rel ) {
            $a      = $rel->attributes();
            $id     = (string) ( $a['Id'] ?? '' );
            $target = (string) ( $a['Target'] ?? '' );
            if ( $id !== '' && $target !== '' ) $map[ $id ] = $target;
        }
        return $map;
    }

    private static function parse_numbering( ?string $xml ): array {
        if ( ! $xml ) return [];
        $sx = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR );
        if ( ! ( $sx instanceof \SimpleXMLElement ) ) return [];
        $ns = $sx->getNamespaces( true );
        $w  = $ns['w'] ?? 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        // abstractNumId => first-level numFmt
        $abstract = [];
        foreach ( $sx->children( $w )->abstractNum as $an ) {
            $aid = (string) ( $an->attributes( $w )['abstractNumId'] ?? '' );
            if ( $aid === '' ) continue;
            $fmt = '';
            foreach ( $an->children( $w )->lvl as $lvl ) {
                $ilvl    = (string) ( $lvl->attributes( $w )['ilvl'] ?? '' );
                $numFmt  = $lvl->children( $w )->numFmt;
                $val     = $numFmt ? (string) ( $numFmt->attributes( $w )['val'] ?? '' ) : '';
                if ( $ilvl === '0' ) { $fmt = $val; break; }
                if ( $fmt === '' )    $fmt = $val;
            }
            $abstract[ $aid ] = $fmt;
        }

        // numId => abstractNumId => fmt
        $map = [];
        foreach ( $sx->children( $w )->num as $num ) {
            $nid = (string) ( $num->attributes( $w )['numId'] ?? '' );
            $abs = $num->children( $w )->abstractNumId;
            $aid = $abs ? (string) ( $abs->attributes( $w )['val'] ?? '' ) : '';
            if ( $nid !== '' && isset( $abstract[ $aid ] ) ) {
                $map[ $nid ] = $abstract[ $aid ];
            }
        }
        return $map;
    }

    private static function parse_xml( string $raw_xml ): array {
        $xml = simplexml_load_string(
            $raw_xml,
            'SimpleXMLElement',
            LIBXML_NOCDATA | LIBXML_NOERROR
        );
        // Note: cannot use `! $xml` — SimpleXMLElement casts to false when it has only child elements
        if ( ! ( $xml instanceof \SimpleXMLElement ) ) return [ 'title' => '', 'sections' => [] ];

        // Register namespaces
        $ns = $xml->getNamespaces( true );
        $w  = $ns['w'] ?? 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        $title    = '';
        $sections = [];
        $current  = null; // [ 'heading' => '', 'html_parts' => [] ]

        $body = $xml->children( $w )->body;
        if ( $body === null || $body->count() === 0 ) return [ 'title' => '', 'sections' => [] ];

        foreach ( $body->children( $w ) as $node_name => $node ) {
            if ( $node_name !== 'p' ) continue;

            $style = self::get_style( $node, $w );
            $html  = self::para_to_html( $node, $w, $style );
            $text  = self::get_plain_text( $node, $w );

            if ( ! $text ) continue;

            // H1 → title
            if ( self::is_heading( $style, 1 ) ) {
                if ( ! $title ) $title = $text;
                continue;
            }

            // H2 → new section
            if ( self::is_heading( $style, 2 ) ) {
                if ( $current !== null ) $sections[] = $current;
                $current = [ 'heading' => $text, 'html_parts' => [] ];
                continue;
            }

            // Content paragraph
            if ( $current === null ) {
                $current = [ 'heading' => '', 'html_parts' => [] ];
            }

            if ( $html ) {
                $current['html_parts'][] = $html;
            }
        }

        if ( $current !== null ) $sections[] = $current;

        // Build final sections with joined HTML
        $final = [];
        foreach ( $sections as $sec ) {
            $final[] = [
                'heading' => $sec['heading'],
                'content' => self::wrap_list_items( $sec['html_parts'] ),
            ];
        }

        return [ 'title' => $title, 'sections' => $final ];
    }

    private static function get_style( \SimpleXMLElement $p, string $w ): string {
        $pPr = $p->children( $w )->pPr;
        if ( ! $pPr ) return '';
        $pStyle = $pPr->children( $w )->pStyle;
        if ( ! $pStyle ) return '';
        $attrs = $pStyle->attributes( $w );
        return (string) ( $attrs['val'] ?? '' );
    }

    private static function is_heading( string $style, int $level ): bool {
        if ( strcasecmp( $style, "Heading{$level}" ) === 0
            || strcasecmp( $style, "Heading {$level}" ) === 0 ) {
            return true;
        }
        // Word's "Title" style counts as H1 (it's how the built-in Title button stores it)
        return $level === 1 && strcasecmp( $style, 'Title' ) === 0;
    }

    private static function get_plain_text( \SimpleXMLElement $p, string $w ): string {
        $text = '';
        foreach ( $p->children( $w ) as $name => $child ) {
            if ( $name === 'r' ) {
                foreach ( $child->children( $w ) as $rname => $rchild ) {
                    if ( $rname === 't' ) $text .= (string) $rchild;
                }
            } elseif ( $name === 'hyperlink' ) {
                foreach ( $child->children( $w ) as $rname2 => $r ) {
                    if ( $rname2 === 'r' ) {
                        foreach ( $r->children( $w ) as $tname => $t ) {
                            if ( $tname === 't' ) $text .= (string) $t;
                        }
                    }
                }
            }
        }
        return trim( $text );
    }

    private static function para_to_html( \SimpleXMLElement $p, string $w, string $style ): ?string {
        $plain = self::get_plain_text( $p, $w );
        if ( ! $plain ) return null;

        $inline = self::runs_to_inline_html( $p, $w );

        // Sub-headings
        if ( self::is_heading( $style, 3 ) ) return "<h3>{$inline}</h3>";
        if ( self::is_heading( $style, 4 ) ) return "<h4>{$inline}</h4>";

        // List detection — only via Word's native numPr (ignores literal "1.", "a.", "1)" text)
        $pPr = $p->children( $w )->pPr;
        if ( $pPr ) {
            $numPr = $pPr->children( $w )->numPr;
            if ( $numPr && $numPr->count() ) {
                $numId_node = $numPr->children( $w )->numId;
                $numId      = $numId_node ? (string) ( $numId_node->attributes( $w )['val'] ?? '' ) : '';
                $fmt        = self::$num_formats[ $numId ] ?? '';
                // bullet / no-format → ul; decimal / lowerLetter / lowerRoman / upper* → ol
                $tag = ( $fmt !== '' && $fmt !== 'bullet' && $fmt !== 'none' ) ? 'ol' : 'ul';
                // Style-name fallback when numbering.xml lookup yielded nothing
                if ( $fmt === '' && ( stripos( $style, 'Number' ) !== false || stripos( $style, 'ListNumber' ) !== false ) ) {
                    $tag = 'ol';
                }
                return "<li data-list=\"{$tag}\">{$inline}</li>";
            }
        }

        return "<p>{$inline}</p>";
    }

    private static function runs_to_inline_html( \SimpleXMLElement $p, string $w ): string {
        $rNs  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $html = '';
        foreach ( $p->children( $w ) as $name => $child ) {
            if ( $name === 'r' ) {
                $html .= self::run_to_html( $child, $w );
            } elseif ( $name === 'hyperlink' ) {
                $link_html = '';
                foreach ( $child->children( $w ) as $rn => $r ) {
                    if ( $rn === 'r' ) $link_html .= self::run_to_html( $r, $w );
                }
                if ( $link_html === '' ) continue;

                $rid    = (string) ( $child->attributes( $rNs )['id']     ?? '' );
                $anchor = (string) ( $child->attributes( $w )['anchor']   ?? '' );

                $href = '';
                if ( $rid !== '' && isset( self::$rels[ $rid ] ) ) {
                    $href = self::$rels[ $rid ];
                    if ( $anchor !== '' ) $href .= '#' . $anchor;
                } elseif ( $anchor !== '' ) {
                    $href = '#' . $anchor;
                }

                if ( $href === '' ) {
                    $html .= $link_html;
                    continue;
                }

                $href_esc = function_exists( 'esc_url' ) ? esc_url( $href ) : htmlspecialchars( $href, ENT_QUOTES );
                $extra    = ( $rid !== '' && isset( self::$rels[ $rid ] ) )
                    ? ' target="_blank" rel="noopener noreferrer"'
                    : '';
                $html .= "<a href=\"{$href_esc}\"{$extra}>{$link_html}</a>";
            }
        }
        return $html;
    }

    private static function run_to_html( \SimpleXMLElement $r, string $w ): string {
        $text      = '';
        $bold      = false;
        $italic    = false;
        $underline = false;

        foreach ( $r->children( $w ) as $rname => $rchild ) {
            if ( $rname === 't' ) {
                $text .= esc_html( (string) $rchild );
            } elseif ( $rname === 'rPr' ) {
                $bold      = self::is_toggled( $rchild, $w, 'b' );
                $italic    = self::is_toggled( $rchild, $w, 'i' );
                $underline = self::has_underline( $rchild, $w );
            }
        }

        if ( $text === '' ) return '';
        if ( $underline ) $text = "<u>{$text}</u>";
        if ( $italic )    $text = "<em>{$text}</em>";
        if ( $bold )      $text = "<strong>{$text}</strong>";
        return $text;
    }

    /**
     * A toggle property like <w:b/> is true when present without a val,
     * or when val is anything other than 0/false/off.
     */
    private static function is_toggled( \SimpleXMLElement $rPr, string $w, string $tag ): bool {
        $children = $rPr->children( $w );
        if ( ! isset( $children->{$tag} ) ) return false;
        $val_attr = $children->{$tag}->attributes( $w )['val'] ?? null;
        if ( $val_attr === null ) return true;
        $val = strtolower( (string) $val_attr );
        return ! in_array( $val, [ '0', 'false', 'off' ], true );
    }

    private static function has_underline( \SimpleXMLElement $rPr, string $w ): bool {
        $children = $rPr->children( $w );
        if ( ! isset( $children->u ) ) return false;
        $val_attr = $children->u->attributes( $w )['val'] ?? null;
        if ( $val_attr === null ) return true; // <w:u/> defaults to single
        $val = strtolower( (string) $val_attr );
        return $val !== '' && $val !== 'none';
    }

    private static function wrap_list_items( array $parts ): string {
        $result = [];
        $i      = 0;

        while ( $i < count( $parts ) ) {
            $part = $parts[ $i ];
            if ( preg_match( '/<li data-list="(ul|ol)">/', $part, $m ) ) {
                $tag   = $m[1];
                $items = [ preg_replace( '/ data-list="[^"]*"/', '', $part ) ];
                $i++;
                while ( $i < count( $parts ) && preg_match( '/<li data-list="(ul|ol)">/', $parts[ $i ], $m2 ) && $m2[1] === $tag ) {
                    $items[] = preg_replace( '/ data-list="[^"]*"/', '', $parts[ $i ] );
                    $i++;
                }
                $result[] = "<{$tag}>" . implode( '', $items ) . "</{$tag}>";
            } else {
                $result[] = $part;
                $i++;
            }
        }

        return implode( "\n", $result );
    }
}
