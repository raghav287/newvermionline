<?php
/**
 * Reads .xlsx and .csv files and writes .xlsx and .csv files, with no external libraries.
 * .xlsx needs PHP's zip, dom and xmlreader extensions (standard on Hostinger).
 */

/** Returns ['rows' => [sheet row number => [cell, ...]], 'date1904' => bool, 'sheet' => name]. */
function sheet_read(string $path, string $ext, int $maxRows = 20000): array
{
    if ($ext === 'csv' || $ext === 'txt') {
        return csv_read($path, $maxRows);
    }
    if ($ext === 'xlsx') {
        return xlsx_read($path, $maxRows);
    }
    throw new RuntimeException('Upload the PayU file as .xlsx or .csv. Older .xls files must be saved again as .xlsx first.');
}

function xlsx_col_index(string $ref): int
{
    $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}

function xlsx_col_letter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function xlsx_xml(string $xml): DOMDocument
{
    if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
        libxml_disable_entity_loader(true);
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('The .xlsx file is damaged. Download it again from PayU.');
    }
    return $dom;
}

function xlsx_read(string $path, int $maxRows): array
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
        throw new RuntimeException('This server cannot open .xlsx files. Save the file as CSV and upload that instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('This is not a valid .xlsx file.');
    }
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook === false) {
        $zip->close();
        throw new RuntimeException('This is not a valid .xlsx file (no workbook inside).');
    }
    $wb = xlsx_xml($workbook);
    $date1904 = false;
    foreach ($wb->getElementsByTagNameNS('*', 'workbookPr') as $n) {
        $v = strtolower($n->getAttribute('date1904'));
        $date1904 = $v === '1' || $v === 'true';
    }
    $sheetName = '';
    $rid = '';
    foreach ($wb->getElementsByTagNameNS('*', 'sheet') as $s) {
        if (in_array($s->getAttribute('state'), ['hidden', 'veryHidden'], true)) {
            continue;
        }
        $sheetName = $s->getAttribute('name');
        $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        break;
    }
    $target = 'xl/worksheets/sheet1.xml';
    $sharedPath = 'xl/sharedStrings.xml';
    if ($rels !== false) {
        foreach (xlsx_xml($rels)->getElementsByTagNameNS('*', 'Relationship') as $r) {
            $t = $r->getAttribute('Target');
            $full = $t !== '' && $t[0] === '/' ? ltrim($t, '/') : 'xl/' . $t;
            if ($r->getAttribute('Id') === $rid) {
                $target = $full;
            }
            if (substr($r->getAttribute('Type'), -13) === 'sharedStrings') {
                $sharedPath = $full;
            }
        }
    }
    $stat = $zip->statName($target);
    if ($stat === false) {
        $zip->close();
        throw new RuntimeException('Could not find the first sheet inside this .xlsx file.');
    }
    if ($stat['size'] > 300 * 1024 * 1024) {
        $zip->close();
        throw new RuntimeException('This sheet is too large to import. Split it into smaller files.');
    }
    $shared = [];
    $ss = $zip->getFromName($sharedPath);
    $zip->close();
    if ($ss !== false) {
        $xr = new XMLReader();
        $xr->XML($ss, 'UTF-8', LIBXML_NONET);
        $cur = null;
        $inPhonetic = false;
        while ($xr->read()) {
            if ($xr->nodeType === XMLReader::ELEMENT) {
                if ($xr->localName === 'si') {
                    if ($xr->isEmptyElement) {
                        $shared[] = '';
                    } else {
                        $cur = '';
                    }
                } elseif ($xr->localName === 'rPh' && !$xr->isEmptyElement) {
                    $inPhonetic = true;
                } elseif ($xr->localName === 't' && $cur !== null && !$inPhonetic) {
                    $cur .= $xr->readString();
                }
            } elseif ($xr->nodeType === XMLReader::END_ELEMENT) {
                if ($xr->localName === 'si') {
                    $shared[] = (string) $cur;
                    $cur = null;
                } elseif ($xr->localName === 'rPh') {
                    $inPhonetic = false;
                }
            }
        }
        $xr->close();
    }

    $xr = new XMLReader();
    if (!@$xr->open('zip://' . $path . '#' . $target, 'UTF-8', LIBXML_NONET)) {
        throw new RuntimeException('Could not read the first sheet of this .xlsx file.');
    }
    $rows = [];
    $lastRow = 0;
    while ($xr->read()) {
        if ($xr->nodeType !== XMLReader::ELEMENT || $xr->localName !== 'row') {
            continue;
        }
        $r = (int) $xr->getAttribute('r');
        $r = $r > 0 ? $r : $lastRow + 1;
        $lastRow = $r;
        $cells = [];
        if (!$xr->isEmptyElement) {
            $next = 0;
            while ($xr->read()) {
                if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->localName === 'row') {
                    break;
                }
                if ($xr->nodeType !== XMLReader::ELEMENT || $xr->localName !== 'c') {
                    continue;
                }
                $ref = (string) $xr->getAttribute('r');
                $type = (string) $xr->getAttribute('t');
                $ci = $ref !== '' ? xlsx_col_index($ref) : $next;
                $next = $ci + 1;
                $value = '';
                if (!$xr->isEmptyElement) {
                    while ($xr->read()) {
                        if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->localName === 'c') {
                            break;
                        }
                        if ($xr->nodeType === XMLReader::ELEMENT && ($xr->localName === 'v' || $xr->localName === 'is')) {
                            $value = $xr->readString();
                        }
                    }
                }
                if ($type === 's') {
                    $value = $shared[(int) $value] ?? '';
                } elseif ($type === 'b') {
                    $value = $value === '1' ? 'TRUE' : 'FALSE';
                } elseif ($type === 'e') {
                    $value = '';
                }
                $cells[$ci] = $value;
            }
        }
        if (!$cells || !array_filter($cells, function ($v) { return trim((string) $v) !== ''; })) {
            continue;
        }
        $dense = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $i => $v) {
            $dense[$i] = $v;
        }
        $rows[$r] = $dense;
        if (count($rows) > $maxRows + 20) {
            $xr->close();
            throw new RuntimeException('This file has more than ' . number_format($maxRows) . ' rows. Split it into smaller files.');
        }
    }
    $xr->close();
    if (!$rows) {
        throw new RuntimeException('The first sheet of this file is empty.');
    }
    return ['rows' => $rows, 'date1904' => $date1904, 'sheet' => $sheetName];
}

function csv_read(string $path, int $maxRows): array
{
    $raw = (string) file_get_contents($path);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    } elseif ((substr($raw, 0, 2) === "\xFF\xFE" || substr($raw, 0, 2) === "\xFE\xFF") && function_exists('mb_convert_encoding')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    if (trim($raw) === '') {
        throw new RuntimeException('The CSV file is empty.');
    }
    $first = strtok($raw, "\n");
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = (string) key($counts);
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    $n = 0;
    $escape = PHP_VERSION_ID >= 70400 ? '' : '\\';
    while (($cells = fgetcsv($fh, 0, $delim, '"', $escape)) !== false) {
        $n++;
        if ($cells === [null] || !array_filter($cells, function ($v) { return trim((string) $v) !== ''; })) {
            continue;
        }
        $rows[$n] = array_map(function ($v) { return $v === null ? '' : (string) $v; }, $cells);
        if (count($rows) > $maxRows + 20) {
            throw new RuntimeException('This file has more than ' . number_format($maxRows) . ' rows. Split it into smaller files.');
        }
    }
    fclose($fh);
    return ['rows' => $rows, 'date1904' => false, 'sheet' => 'CSV'];
}

/** Excel stores dates as day numbers; converts one to 'Y-m-d H:i:s'. */
function excel_serial_to_datetime(float $serial, bool $date1904 = false): string
{
    $days = floor($serial);
    $seconds = (int) round(($serial - $days) * 86400);
    $base = $date1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);
    return gmdate('Y-m-d H:i:s', (int) ($base + $days * 86400 + $seconds));
}

/** Text cells that start with = or @ are prefixed so Excel shows them instead of running them. */
function sheet_safe_text(string $v): string
{
    return ($v !== '' && ($v[0] === '=' || $v[0] === '@' || (($v[0] === '+' || $v[0] === '-') && !is_numeric($v)))) ? "'" . $v : $v;
}

function csv_send(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, array_map(function ($v) { return is_string($v) ? sheet_safe_text($v) : ($v === null ? '' : $v); }, $r));
    }
    fclose($out);
    exit;
}

/** Writes an .xlsx file. Strings stay text (so long IDs keep every digit); ints and floats become numbers. */
function xlsx_write(string $path, array $header, array $rows): void
{
    $esc = function ($s) {
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
    $all = array_merge([$header], $rows);
    foreach ($all as $ri => $r) {
        $sheet .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($r) as $ci => $v) {
            $ref = xlsx_col_letter($ci) . ($ri + 1);
            if ($ri > 0 && (is_int($v) || is_float($v))) {
                $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } elseif ($v !== null && $v !== '') {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $esc(sheet_safe_text((string) $v)) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the Excel file.');
    }
    $zip->addFromString('[Content_Types].xml', $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $x . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', $x . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

function xlsx_send(string $filename, array $header, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        csv_send(preg_replace('/\.xlsx$/', '.csv', $filename), $header, $rows);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    xlsx_write($tmp, $header, $rows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function sheet_send(string $format, string $basename, array $header, array $rows): void
{
    if ($format === 'xlsx') {
        xlsx_send($basename . '.xlsx', $header, $rows);
    }
    csv_send($basename . '.csv', $header, $rows);
}
