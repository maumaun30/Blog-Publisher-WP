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

    public static function parse( string $file_path ): array {
        if ( ! file_exists( $file_path ) ) {
            throw new \RuntimeException( "File not found: {$file_path}" );
        }

        $xml = self::extract_document_xml( $file_path );
        if ( ! $xml ) {
            throw new \RuntimeException( "Could not read document.xml from docx." );
        }

        return self::parse_xml( $xml );
    }

    // ── Private ──────────────────────────────────────────────────────────

    private static function extract_document_xml( string $path ): ?string {
        $zip = new \ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return null;
        }
        $content = $zip->getFromName( 'word/document.xml' );
        $zip->close();
        return $content ?: null;
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

        // List detection via numPr
        $pPr = $p->children( $w )->pPr;
        if ( $pPr ) {
            $numPr = $pPr->children( $w )->numPr;
            if ( $numPr && $numPr->count() ) {
                $tag = ( stripos( $style, 'Number' ) !== false || stripos( $style, 'ListNumber' ) !== false )
                    ? 'ol' : 'ul';
                return "<li data-list=\"{$tag}\">{$inline}</li>";
            }
        }

        return "<p>{$inline}</p>";
    }

    private static function runs_to_inline_html( \SimpleXMLElement $p, string $w ): string {
        $html = '';
        foreach ( $p->children( $w ) as $name => $child ) {
            if ( $name === 'r' ) {
                $html .= self::run_to_html( $child, $w );
            } elseif ( $name === 'hyperlink' ) {
                $link_text = '';
                foreach ( $child->children( $w ) as $rn => $r ) {
                    if ( $rn === 'r' ) $link_text .= self::run_to_html( $r, $w );
                }
                // Relationship-based hrefs need rels file; use # as fallback
                $html .= "<a href=\"#\">{$link_text}</a>";
            }
        }
        return $html;
    }

    private static function run_to_html( \SimpleXMLElement $r, string $w ): string {
        $text = '';
        $bold   = false;
        $italic = false;

        foreach ( $r->children( $w ) as $rname => $rchild ) {
            if ( $rname === 't' ) {
                $text .= esc_html( (string) $rchild );
            } elseif ( $rname === 'rPr' ) {
                $bold   = (bool) $rchild->children( $w )->b;
                $italic = (bool) $rchild->children( $w )->i;
            }
        }

        if ( ! $text ) return '';
        if ( $bold && $italic ) return "<strong><em>{$text}</em></strong>";
        if ( $bold )   return "<strong>{$text}</strong>";
        if ( $italic ) return "<em>{$text}</em>";
        return $text;
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
                while ( $i < count( $parts ) && preg_match( '/<li data-list="/', $parts[ $i ] ) ) {
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
