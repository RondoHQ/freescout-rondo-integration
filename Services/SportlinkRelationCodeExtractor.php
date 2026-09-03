<?php

namespace Modules\RondoIntegration\Services;

class SportlinkRelationCodeExtractor
{
    const SENDER = 'no-reply@sportlinkservices.nl';
    const SUBJECT = 'Overschrijvingsverzoek';

    public function extract($subject, $sender, $html)
    {
        if (!hash_equals(self::SUBJECT, trim((string) $subject))
            || !hash_equals(self::SENDER, strtolower(trim((string) $sender)))
            || trim((string) $html) === ''
        ) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="rondo-sportlink-message">' . (string) $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $codes = [];
        foreach ($document->getElementsByTagName('td') as $cell) {
            if ($this->label($cell->textContent) !== 'relatiecode') {
                continue;
            }

            $valueCell = $cell->nextSibling;
            while ($valueCell && $valueCell->nodeType !== XML_ELEMENT_NODE) {
                $valueCell = $valueCell->nextSibling;
            }
            if (!$valueCell || strtolower($valueCell->nodeName) !== 'td') {
                return null;
            }

            $code = strtoupper($this->text($valueCell->textContent));
            if (!preg_match('/^[A-Z0-9]{4,20}$/D', $code)) {
                return null;
            }
            $codes[$code] = true;
        }

        return count($codes) === 1 ? (string) key($codes) : null;
    }

    private function label($value)
    {
        return strtolower(rtrim($this->text($value), " \t\n\r\0\x0B:"));
    }

    private function text($value)
    {
        $value = str_replace("\xC2\xA0", ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim(is_string($value) ? $value : '');
    }
}
