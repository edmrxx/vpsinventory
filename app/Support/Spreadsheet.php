<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * An Excel workbook (.xlsx), written by hand (phase 34).
 *
 * No Composer package: this system is deployed by uploading files, so —
 * like `ImageFile` using GD — it is built on what PHP already carries:
 * ZipArchive and XMLWriter. Each sheet streams to a temporary file row by
 * row, so twenty thousand sales never sit in memory at once.
 *
 * What keeps Excel from refusing the file, stated once:
 *
 * - every string is an inline string (`t="inlineStr"`), never a formula,
 *   so a customer named `=HYPERLINK(…)` stays plain text;
 * - characters XML forbids (a pasted control character) are removed first:
 *   one of them would corrupt the whole workbook;
 * - every `count=` in the styles matches its children, and the two fills
 *   Excel insists on (none, gray125) come first;
 * - sheet names are at most 31 characters, without `[]:*?/\`, and unique;
 * - a number is written with a dot and no separators; a date is the serial
 *   worked out from its Y-m-d string, so no time zone can move it a day;
 * - no `autoFilter`, which would need a defined name as well.
 */
final class Spreadsheet
{
    /* Cell styles, in the order of `cellXfs` in styles.xml. */
    public const PLAIN = 0;

    public const HEADER = 1;

    public const MONEY = 2;

    public const DATE = 3;

    public const TITLE = 4;

    public const BOLD_MONEY = 5;

    public const BOLD = 6;

    public const NOTE = 7;

    /** @var list<array{name:string, path:string}> */
    private array $sheets = [];

    private ?XMLWriter $writer = null;

    private int $row = 0;

    private string $directory;

    public function __construct()
    {
        if (! self::available()) {
            throw new RuntimeException('The server\'s PHP has no zip extension, so a spreadsheet cannot be made. Ask the host to switch on "zip".');
        }

        $this->directory = storage_path('app/private/exports');

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    public static function available(): bool
    {
        return class_exists(ZipArchive::class) && class_exists(XMLWriter::class);
    }

    /* ------------------------------------------------------------- Cells */

    /** @return array{t:string, v:mixed, s:int} */
    public static function money(int|float|string|null $value, bool $bold = false): array
    {
        return ['t' => 'n', 'v' => $value ?? 0, 's' => $bold ? self::BOLD_MONEY : self::MONEY];
    }

    /** A plain number — a quantity or a count. @return array{t:string, v:mixed, s:int} */
    public static function number(int|float|string|null $value, bool $bold = false): array
    {
        return ['t' => 'n', 'v' => $value ?? 0, 's' => $bold ? self::BOLD : self::PLAIN];
    }

    /** @return array{t:string, v:mixed, s:int}|null */
    public static function date(?string $ymd): ?array
    {
        return $ymd === null || $ymd === '' ? null : ['t' => 'd', 'v' => substr($ymd, 0, 10), 's' => self::DATE];
    }

    /** @return array{t:string, v:mixed, s:int} */
    public static function text(?string $value, int $style = self::PLAIN): array
    {
        return ['t' => 's', 'v' => (string) $value, 's' => $style];
    }

    /* ------------------------------------------------------------ Sheets */

    /**
     * Start a sheet. `$widths` are column widths in characters; a sheet that
     * opens with a header row may keep it in view while scrolling.
     *
     * @param  list<int|float>  $widths
     */
    public function sheet(string $name, array $widths = [], bool $freezeHeader = false): self
    {
        $this->closeSheet();

        $path = tempnam($this->directory, 'sheet');
        $this->sheets[] = ['name' => $this->uniqueName($name), 'path' => $path];
        $this->row = 0;

        $writer = new XMLWriter;
        $writer->openUri($path);
        $writer->startDocument('1.0', 'UTF-8', 'yes');
        $writer->startElement('worksheet');
        $writer->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $writer->writeAttribute('xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $writer->startElement('sheetViews');
        $writer->startElement('sheetView');
        $writer->writeAttribute('workbookViewId', '0');
        if (count($this->sheets) === 1) {
            $writer->writeAttribute('tabSelected', '1');
        }
        if ($freezeHeader) {
            $writer->startElement('pane');
            $writer->writeAttribute('ySplit', '1');
            $writer->writeAttribute('topLeftCell', 'A2');
            $writer->writeAttribute('activePane', 'bottomLeft');
            $writer->writeAttribute('state', 'frozen');
            $writer->endElement();
            $writer->startElement('selection');
            $writer->writeAttribute('pane', 'bottomLeft');
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endElement();

        $writer->startElement('sheetFormatPr');
        $writer->writeAttribute('defaultRowHeight', '15');
        $writer->endElement();

        if ($widths !== []) {
            $writer->startElement('cols');
            foreach (array_values($widths) as $index => $width) {
                $writer->startElement('col');
                $writer->writeAttribute('min', (string) ($index + 1));
                $writer->writeAttribute('max', (string) ($index + 1));
                $writer->writeAttribute('width', (string) $width);
                $writer->writeAttribute('customWidth', '1');
                $writer->endElement();
            }
            $writer->endElement();
        }

        $writer->startElement('sheetData');
        $this->writer = $writer;

        return $this;
    }

    /**
     * One row. A cell is a plain string, an int or float, null (empty), or
     * one of the arrays `money()`, `number()`, `date()` and `text()` make.
     * `$style` applies to the plain strings and numbers in it.
     *
     * @param  list<mixed>  $cells
     */
    public function row(array $cells, ?int $style = null): self
    {
        if ($this->writer === null) {
            throw new RuntimeException('Start a sheet before writing a row.');
        }

        $this->row++;
        $writer = $this->writer;
        $writer->startElement('row');
        $writer->writeAttribute('r', (string) $this->row);

        foreach (array_values($cells) as $index => $cell) {
            $cell = $this->normalise($cell, $style);

            if ($cell === null) {
                continue;
            }

            $writer->startElement('c');
            $writer->writeAttribute('r', self::column($index).$this->row);

            if ($cell['s'] !== self::PLAIN) {
                $writer->writeAttribute('s', (string) $cell['s']);
            }

            if ($cell['t'] === 's') {
                $writer->writeAttribute('t', 'inlineStr');
                $writer->startElement('is');
                $writer->startElement('t');
                $writer->writeAttribute('xml:space', 'preserve');
                $writer->text(self::clean($cell['v']));
                $writer->endElement();
                $writer->endElement();
            } else {
                $writer->writeElement('v', $cell['t'] === 'd' ? (string) self::serial($cell['v']) : self::numeral($cell['v']));
            }

            $writer->endElement();
        }

        $writer->endElement();

        return $this;
    }

    /** An empty row, to space the blocks of a summary apart. */
    public function blank(): self
    {
        $this->row++;

        return $this;
    }

    /**
     * Write the workbook and return where it is. The caller sends it and
     * deletes it (`download()->deleteFileAfterSend()`).
     */
    public function save(): string
    {
        $this->closeSheet();

        if ($this->sheets === []) {
            $this->sheet('Sheet1');
            $this->closeSheet();
        }

        $path = $this->directory.'/'.Str::uuid()->toString().'.xlsx';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The spreadsheet could not be written.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFile($sheet['path'], 'xl/worksheets/sheet'.($index + 1).'.xml');
        }

        $zip->close();

        foreach ($this->sheets as $sheet) {
            @unlink($sheet['path']);
        }

        return $path;
    }

    /**
     * Throw away what was written so far: an export that failed part-way
     * leaves no sheet behind in `storage/app/private/exports`.
     */
    public function discard(): void
    {
        if ($this->writer !== null) {
            $this->writer->flush();
            $this->writer = null;
        }

        foreach ($this->sheets as $sheet) {
            @unlink($sheet['path']);
        }

        $this->sheets = [];
    }

    /* ----------------------------------------------------------- Helpers */

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function column(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    /** The text with everything XML 1.0 forbids taken out. */
    public static function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text);
    }

    /** Excel's day number for a Y-m-d date: days since 30 December 1899. */
    public static function serial(string $ymd): int
    {
        $utc = new DateTimeZone('UTC');

        return (int) (new DateTimeImmutable('1899-12-30', $utc))
            ->diff(new DateTimeImmutable(substr($ymd, 0, 10), $utc))
            ->format('%r%a');
    }

    /** A number as Excel reads it: a dot, no separators, no exponent. */
    public static function numeral(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value))) {
            return trim($value);
        }

        $formatted = rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }

    /** @return array{t:string, v:mixed, s:int}|null */
    private function normalise(mixed $cell, ?int $style): ?array
    {
        if ($cell === null) {
            return null;
        }

        if (is_array($cell)) {
            return $cell;
        }

        if (is_int($cell) || is_float($cell)) {
            return ['t' => 'n', 'v' => $cell, 's' => $style ?? self::PLAIN];
        }

        return ['t' => 's', 'v' => (string) $cell, 's' => $style ?? self::PLAIN];
    }

    private function uniqueName(string $name): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', self::clean($name)));
        $name = mb_substr($name === '' ? 'Sheet' : $name, 0, 31);
        $taken = array_map(fn ($sheet) => mb_strtolower($sheet['name']), $this->sheets);
        $candidate = $name;

        for ($n = 2; in_array(mb_strtolower($candidate), $taken, true); $n++) {
            $suffix = ' ('.$n.')';
            $candidate = mb_substr($name, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    private function closeSheet(): void
    {
        if ($this->writer === null) {
            return;
        }

        $this->writer->endElement(); // sheetData
        $this->writer->endElement(); // worksheet
        $this->writer->endDocument();
        $this->writer->flush();
        $this->writer = null;
    }

    private function contentTypes(): string
    {
        $sheets = '';

        foreach (array_keys($this->sheets) as $index) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" '
                .'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" '
                .'sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews>'
            .'<sheets>'.$sheets.'</sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        $relationships = '';

        foreach (array_keys($this->sheets) as $index) {
            $relationships .= '<Relationship Id="rId'.($index + 1).'" '
                .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                .'Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }

        $styles = count($this->sheets) + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'<Relationship Id="rId'.$styles.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * The styles, in the order of the constants above. numFmt 4 is Excel's
     * built-in `#,##0.00`; 164 is the first custom number.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
            .'<fonts count="4">'
            .'<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F1F4"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
