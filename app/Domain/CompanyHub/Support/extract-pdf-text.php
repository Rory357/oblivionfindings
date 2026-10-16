<?php

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

// Runs without the application/database in a bounded child process. Never accepts a URL or file path.
require dirname(__DIR__, 4).'/vendor/autoload.php';

try {
    $bytes = stream_get_contents(STDIN, 10 * 1024 * 1024 + 1);
    if (! is_string($bytes) || strlen($bytes) > 10 * 1024 * 1024 || ! str_starts_with($bytes, '%PDF-')) {
        throw new RuntimeException('Unsupported input.');
    }
    $config = new Config;
    $config->setRetainImageContent(false);
    $config->setDecodeMemoryLimit(8 * 1024 * 1024);
    $document = (new Parser([], $config))->parseContent($bytes);
    $text = '';
    foreach (array_slice($document->getPages(), 0, 200) as $page) {
        $text .= ' '.$page->getText();
        if (mb_strlen($text) >= 250000) {
            break;
        }
    }
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    echo json_encode(mb_substr(trim($text), 0, 250000), JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable) {
    echo '""';
}
