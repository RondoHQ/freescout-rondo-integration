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
        $nonce = $this->base64Url(random_bytes(24));
        $markup = $this->sanitize($remoteHtml);
        $appUrl = function_exists('config') ? config('app.url') : 'https://freescout.example.test';
        $parent = $this->origin($appUrl);
        $accent = $this->settings->get('accent', '#0069AA');
        $surface = $this->settings->get('accent_surface', '#D9EDF7');
        $script = "(function(){var c=" . json_encode($channel) . ",o=" . json_encode($parent)
            . ",t=null;function send(){clearTimeout(t);t=setTimeout(function(){var h=Math.ceil(document.documentElement.scrollHeight);"
            . "if(Number.isFinite(h)){parent.postMessage({type:'rondo-sidebar-height',version:1,channel:c,height:Math.max(160,Math.min(1600,h))},o);}},40);}"
            . "new ResizeObserver(send).observe(document.documentElement);addEventListener('load',send);send();}());";
        $csp = "default-src 'none'; script-src 'nonce-" . $nonce . "'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'";
        $document = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'
            . htmlspecialchars($csp, ENT_QUOTES, 'UTF-8') . '"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>:root{--rondo-accent:' . htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') . ';--rondo-accent-surface:' . htmlspecialchars($surface, ENT_QUOTES, 'UTF-8')
            . ';color-scheme:light}*{box-sizing:border-box}body{margin:0;padding:10px;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#333;background:#fff}a{color:var(--rondo-accent);font-weight:600}details{border-top:1px solid #ddd;padding:8px 0}summary{cursor:pointer;color:var(--rondo-accent)}.rondo-highlight{background:var(--rondo-accent-surface);padding:8px;border-radius:4px}</style>'
            . '</head><body>' . $markup . '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">' . $script . '</script></body></html>';
        return ['srcdoc' => $document, 'channel' => $channel];
    }

    public function sanitize($html)
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="rondo-root">' . (string) $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $blocked = ['script', 'style', 'form', 'input', 'button', 'textarea', 'select', 'iframe', 'frame', 'object', 'embed', 'base', 'meta', 'link', 'svg', 'math', 'video', 'audio', 'source'];
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
        $base = parse_url($this->settings->baseUrl());
        $target = parse_url($url);
        if (!is_array($base) || !is_array($target) || empty($target['scheme']) || empty($target['host'])) {
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
