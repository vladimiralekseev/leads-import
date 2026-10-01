<?php

namespace App;

use DOMElement;
use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

final class XlsxReader
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private string $path;
    private string $sheetEntry;
    private ?array $sharedStrings = null;

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new RuntimeException('Файл не знайдено');
        }
        $this->path = $path;
        $this->sheetEntry = $this->resolveFirstSheet();
    }

    public function countRows(): int
    {
        $reader = $this->openSheet();
        $count = 0;
        if ($this->moveToFirstRow($reader)) {
            do {
                $count++;
            } while ($reader->next('row'));
        }
        $reader->close();

        return $count;
    }

    public function rows(int $skip = 0): Generator
    {
        $strings = $this->sharedStrings();
        $reader = $this->openSheet();

        if (!$this->moveToFirstRow($reader)) {
            $reader->close();
            return;
        }

        $index = 0;
        do {
            if ($index++ < $skip) {
                continue;
            }

            $row = $reader->expand();
            if (!$row instanceof DOMElement) {
                continue;
            }

            $rowNumber = (int)$row->getAttribute('r') ?: $index;
            yield [$rowNumber, $this->parseRow($row, $strings)];
        } while ($reader->next('row'));

        $reader->close();
    }

    public function header(): array
    {
        foreach ($this->rows(0) as [, $cells]) {
            return $cells;
        }

        return [];
    }

    private function parseRow(DOMElement $row, array $strings): array
    {
        $cells = [];
        $position = 0;

        foreach ($row->childNodes as $c) {
            if (!$c instanceof DOMElement || $c->localName !== 'c') {
                continue;
            }

            $ref = $c->getAttribute('r');
            $col = $ref !== '' ? self::columnIndex($ref) : $position;
            $position = $col + 1;

            $type = $c->getAttribute('t');
            $value = null;

            foreach ($c->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->localName === 'v') {
                    $value = $child->textContent;
                } elseif ($child->localName === 'is') {
                    // inline string: беремо всі <t>, крім фонетичних підказок <rPh>
                    $value = '';
                    foreach ($child->getElementsByTagName('t') as $t) {
                        if ($t->parentNode->localName !== 'rPh') {
                            $value .= $t->textContent;
                        }
                    }
                }
            }

            if ($value === null) {
                continue;
            }

            if ($type === 's') {
                $value = $strings[(int)$value] ?? '';
            } elseif ($type === 'b') {
                $value = $value === '1' ? '1' : '0';
            }

            $cells[$col] = $value;
        }

        return $cells;
    }

    /** "AB12" => 27 (0-based) */
    public static function columnIndex(string $ref): int
    {
        $n = 0;
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ch = ord($ref[$i]);
            if ($ch < 65 || $ch > 90) {
                break;
            }
            $n = $n * 26 + ($ch - 64);
        }

        return $n - 1;
    }

    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $this->sharedStrings = [];
        $entry = $this->findEntry(['xl/sharedStrings.xml', 'xl/sharedstrings.xml']);
        if ($entry === null) {
            return $this->sharedStrings;
        }

        $reader = new XMLReader();
        if (!$reader->open($this->zipUri($entry), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Не вдалося прочитати sharedStrings.xml');
        }

        $current = null;
        $inPhonetic = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'si':
                        $current = '';
                        if ($reader->isEmptyElement) {
                            $this->sharedStrings[] = '';
                            $current = null;
                        }
                        break;
                    case 'rPh':
                        if (!$reader->isEmptyElement) {
                            $inPhonetic++;
                        }
                        break;
                    case 't':
                        if ($current !== null && !$inPhonetic && !$reader->isEmptyElement) {
                            $current .= $reader->readString();
                        }
                        break;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'si' && $current !== null) {
                    $this->sharedStrings[] = $current;
                    $current = null;
                } elseif ($reader->localName === 'rPh') {
                    $inPhonetic--;
                }
            }
        }
        $reader->close();

        return $this->sharedStrings;
    }

    private function openSheet(): XMLReader
    {
        $reader = new XMLReader();
        if (!$reader->open($this->zipUri($this->sheetEntry), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Не вдалося відкрити аркуш у файлі');
        }

        return $reader;
    }

    private function moveToFirstRow(XMLReader $reader): bool
    {
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                return true;
            }
        }

        return false;
    }

    private function zipUri(string $entry): string
    {
        return 'zip://' . $this->path . '#' . $entry;
    }

    private function resolveFirstSheet(): string
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('Файл не є коректним .xlsx (zip)');
        }

        try {
            $workbook = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

            if ($workbook !== false && $rels !== false) {
                $wb = simplexml_load_string($workbook, null, LIBXML_NONET);
                $rl = simplexml_load_string($rels, null, LIBXML_NONET);
                if ($wb && $rl && isset($wb->sheets->sheet[0])) {
                    $rid = (string)$wb->sheets->sheet[0]->attributes(self::NS_REL)['id'];
                    foreach ($rl->Relationship as $rel) {
                        if ((string)$rel['Id'] === $rid) {
                            $target = ltrim((string)$rel['Target'], '/');
                            if (!str_starts_with($target, 'xl/')) {
                                $target = 'xl/' . $target;
                            }
                            if ($zip->locateName($target) !== false) {
                                return $target;
                            }
                        }
                    }
                }
            }

            if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
                return 'xl/worksheets/sheet1.xml';
            }
        } finally {
            $zip->close();
        }

        throw new RuntimeException('У файлі не знайдено жодного аркуша');
    }

    private function findEntry(array $candidates): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            return null;
        }
        try {
            foreach ($candidates as $name) {
                if ($zip->locateName($name) !== false) {
                    return $name;
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }
}
