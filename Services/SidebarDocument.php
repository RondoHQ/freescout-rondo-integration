<?php

namespace Modules\RondoIntegration\Services;

class SidebarDocument
{
    private $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function render($remoteHtml)
    {
        $channel = $this->base64Url(random_bytes(24));
        $markup = $this->sanitize($remoteHtml);
        $appUrl = function_exists('config') ? config('app.url') : 'https://freescout.example.test';
        $parent = $this->origin($appUrl);
        $scriptUrl = rtrim($appUrl, '/') . '/modules/rondointegration/js/sidebar-frame.js';
        $accent = $this->settings->get('accent', '#0069AA');
        $surface = $this->settings->get('accent_surface', '#D9EDF7');
        $csp = "default-src 'none'; script-src " . $parent . "; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'";
        $document = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'
            . htmlspecialchars($csp, ENT_QUOTES, 'UTF-8') . '"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>:root{--rondo-accent:' . htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') . ';--rondo-accent-surface:' . htmlspecialchars($surface, ENT_QUOTES, 'UTF-8')
            . ';color-scheme:light}' . $this->styles() . '</style>'
            . '</head><body data-rondo-channel="' . htmlspecialchars($channel, ENT_QUOTES, 'UTF-8') . '" data-rondo-parent-origin="' . htmlspecialchars($parent, ENT_QUOTES, 'UTF-8') . '">'
            . $markup . '<script src="' . htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8') . '"></script></body></html>';
        return ['srcdoc' => $document, 'channel' => $channel];
    }

    public function sanitize($html)
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="rondo-root">' . (string) $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $blocked = ['script', 'style', 'form', 'input', 'textarea', 'iframe', 'frame', 'object', 'embed', 'base', 'meta', 'link', 'svg', 'math', 'video', 'audio', 'source'];
        foreach ($blocked as $tag) {
            $nodes = [];
            foreach ($document->getElementsByTagName($tag) as $node) {
                $nodes[] = $node;
            }
            foreach ($nodes as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//*') as $node) {
            $remove = [];
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (strpos($name, 'on') === 0 || in_array($name, ['style', 'srcset', 'nonce', 'integrity', 'formaction'], true)) {
                    $remove[] = $attribute->name;
                    continue;
                }
                if ($name === 'href' && !$this->allowedHref($attribute->value)) {
                    $remove[] = $attribute->name;
                }
                if ($name === 'src' && !$this->allowedImage($attribute->value)) {
                    $remove[] = $attribute->name;
                }
            }
            foreach ($remove as $name) {
                $node->removeAttribute($name);
            }
            if (strtolower($node->nodeName) === 'a' && $node->hasAttribute('href')) {
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }
        $root = $document->getElementById('rondo-root');
        if (!$root) {
            return '';
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        return $result;
    }

    private function allowedHref($url)
    {
        if (preg_match('/^(mailto|tel):[^\x00-\x20]+$/i', $url)) {
            return true;
        }
        $target = parse_url($url);
        if (!is_array($target) || empty($target['scheme']) || empty($target['host'])
            || isset($target['user']) || isset($target['pass'])
        ) {
            return false;
        }
        if (strtolower($target['scheme']) === 'https'
            && strtolower($target['host']) === 'club.sportlink.com'
            && !isset($target['port'])
            && empty($target['query']) && empty($target['fragment'])
        ) {
            return preg_match('#^/member/member-details/[A-Z0-9]{4,20}/general/?$#', isset($target['path']) ? $target['path'] : '') === 1;
        }
        if (strtolower($target['scheme']) === 'https'
            && strtolower($target['host']) === 'wa.me'
            && !isset($target['port'])
            && empty($target['query']) && empty($target['fragment'])
        ) {
            return preg_match('#^/\d{8,15}/?$#', isset($target['path']) ? $target['path'] : '') === 1;
        }
        $base = parse_url($this->settings->baseUrl());
        if (!is_array($base)) {
            return false;
        }
        $path = isset($target['path']) ? $target['path'] : '/';
        $prefix = rtrim(isset($base['path']) ? $base['path'] : '', '/');
        return strtolower($target['scheme']) === strtolower($base['scheme'])
            && strtolower($target['host']) === strtolower($base['host'])
            && (!$prefix || strpos($path, $prefix . '/') === 0 || $path === $prefix)
            && !isset($target['user']) && !isset($target['pass']);
    }

    private function allowedImage($url)
    {
        return preg_match('#^data:image/(png|gif|jpe?g|webp);base64,[a-z0-9+/=]+$#i', $url) === 1;
    }

    private function styles()
    {
        return <<<'CSS'
*{box-sizing:border-box}
body{margin:0;padding:4px 12px 12px;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#293946;background:#fff}
a{color:var(--rondo-accent);font-weight:600;text-decoration:none}
a:hover{text-decoration:underline}
[hidden]{display:none!important}
.rondo-profile-choice{margin-bottom:12px;padding:10px;border:1px solid #d8e0e6;border-radius:7px;background:#f7f9fb}
.rondo-profile-choice label{display:block;margin-bottom:5px;color:#677b8d;font-size:11px;letter-spacing:.04em;text-transform:uppercase}
.rondo-profile-choice select{display:block;width:100%;min-height:36px;padding:6px 30px 6px 9px;border:1px solid #b9c6cf;border-radius:5px;background:#fff;color:#293946;font:inherit}
.rondo-profile-choice p{margin:6px 0 0;color:#677b8d;font-size:12px}
.rondo-sidebar{min-width:0}
.rondo-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;padding:9px 10px;border-radius:5px;background:#eef2f5}
.rondo-heading>strong{font-size:14px}
.rondo-mailbox-badge,.rondo-badge{display:inline-flex;align-items:center;border-radius:999px;font-size:11px;font-weight:600;line-height:1.25}
.rondo-mailbox-badge{padding:4px 8px;color:var(--rondo-accent);background:#fff}
.rondo-highlight{padding:12px;border:1px solid #d8e0e6;border-radius:7px;background:var(--rondo-accent-surface)}
.rondo-highlight p{margin:0}
.rondo-badge{padding:3px 7px}
.rondo-badge+.rondo-badge{margin-left:4px}
.rondo-badge--success{color:#176a43;background:#e7f6ed}
.rondo-badge--warning{color:#87500a;background:#fff4df}
.rondo-badge--muted{color:#536878;background:#eef2f5}
.rondo-alert{margin-top:9px;padding:10px 11px;border-left:3px solid #c07a17;background:#fff4df}
.rondo-alert h3{margin:0 0 5px;color:#87500a;font-size:13px}
.rondo-invoice{margin-top:8px;padding-top:8px;border-top:1px solid rgba(135,80,10,.2)}
.rondo-invoice-link{display:inline-block;margin-bottom:2px}
.rondo-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:11px;border-bottom:1px solid #d8e0e6}
.rondo-tab{min-height:40px;padding:8px 3px;border:0;border-bottom:2px solid transparent;background:transparent;color:#677b8d;cursor:pointer;font:inherit;font-size:12px}
.rondo-tab:hover{color:var(--rondo-accent)}
.rondo-tab.is-active,.rondo-tab[aria-selected="true"]{border-bottom-color:var(--rondo-accent);color:var(--rondo-accent);font-weight:600}
.rondo-tab:focus-visible{outline:2px solid var(--rondo-accent);outline-offset:-2px}
.rondo-tab-panel{min-width:0}
.rondo-section{padding:11px 2px;border-bottom:1px solid #d8e0e6}
.rondo-section h3{margin:0 0 7px;color:#677b8d;font-size:11px;letter-spacing:.05em;text-transform:uppercase}
.rondo-rows{display:grid;grid-template-columns:minmax(82px,94px) minmax(0,1fr);gap:0 9px;margin:0}
.rondo-rows dt,.rondo-rows dd{min-width:0;margin:0;padding:4px 0;overflow-wrap:anywhere}
.rondo-rows dt{color:#677b8d}
.rondo-rows dd{font-weight:600}
.rondo-alert .rondo-rows{grid-template-columns:minmax(78px,92px) minmax(0,1fr)}
.rondo-inline-action{display:inline-flex;margin-left:4px;padding:1px 5px;border:1px solid var(--rondo-accent);border-radius:4px;font-size:11px;white-space:nowrap}
.rondo-actions{display:grid;grid-template-columns:minmax(0,1fr);gap:7px;padding-top:12px}
.rondo-action{display:flex;align-items:center;justify-content:center;min-height:38px;padding:8px;border:1px solid var(--rondo-accent);border-radius:5px}
.rondo-action--primary{color:#fff;background:var(--rondo-accent)}
.rondo-action--primary:hover{color:#fff}
.rondo-action--secondary{color:var(--rondo-accent);background:#fff}
.rondo-note{margin:9px 2px 0;color:#7b8d9b;font-size:11px}
@media(max-width:310px){body{padding:4px 9px 9px}.rondo-highlight{padding:11px 9px}.rondo-rows,.rondo-alert .rondo-rows{grid-template-columns:minmax(70px,82px) minmax(0,1fr)}}
CSS;
    }

    private function origin($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function base64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
