<?php

declare(strict_types=1);

namespace Stask;

use DateTimeImmutable;
use DateTimeInterface;

final class Libs
{
    /**
     * Earlier entries win when more than one format matches every date in the file.
     *
     * @var list<string>
     */
    private const DATE_FORMATS = [
        'Y-m-d',
        'Y/m/d',
        'Y.m.d',
        'd-m-Y',
        'd/m/Y',
        'd.m.Y',
        'm-d-Y',
        'm/d/Y',
        'm.d.Y',
    ];

    public static function version(): string
    {
        return '0.1.0';
    }

    /**
     * @return list<array{name: string, size: int, createdAt: string}>
     */
    public static function listFiles(?string $directory = null): array
    {
        $directory ??= dirname(__DIR__) . DIRECTORY_SEPARATOR . 'upload_files';

        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if (!str_ends_with(strtolower($entry), '.csv')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            $size = filesize($path);
            if ($size === false) {
                continue;
            }

            $uploadedAt = self::uploadedAt($entry);
            if ($uploadedAt === null) {
                $mtime = filemtime($path);
                if ($mtime === false) {
                    continue;
                }

                $uploadedAt = (new DateTimeImmutable())->setTimestamp($mtime);
            }

            $files[] = [
                'name' => $entry,
                'size' => $size,
                'createdAt' => $uploadedAt->format(DateTimeInterface::ATOM),
            ];
        }

        usort(
            $files,
            static fn (array $left, array $right): int =>
                [$right['createdAt'], $left['name']] <=> [$left['createdAt'], $right['name']],
        );

        return $files;
    }

    /**
     * @return array{ok: true, file: array{name: string, size: int, createdAt: string}}|array{ok: false, error: string, status: int}
     */
    public static function saveCsv(string $sourcePath, string $originalName, ?string $directory = null): array
    {
        $originalName = basename(str_replace('\\', '/', $originalName));
        if (!str_ends_with(strtolower($originalName), '.csv')) {
            return self::saveError('Only CSV files are allowed.');
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return self::saveError('The file is not a valid CSV.');
        }

        $mime = self::mimeType($sourcePath);
        if ($mime === false || !in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true)) {
            return self::saveError('The file is not a valid CSV.');
        }

        if (!self::isCsvContent($sourcePath)) {
            return self::saveError('The file is not a valid CSV.');
        }

        $safeFilename = self::slug(pathinfo($originalName, PATHINFO_FILENAME));
        if ($safeFilename === '') {
            $safeFilename = 'file';
        }

        $directory ??= dirname(__DIR__) . DIRECTORY_SEPARATOR . 'upload_files';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return self::saveError('Upload failed. Could not create the upload directory.');
        }

        $uploadedAt = new DateTimeImmutable('now');
        $newFilename = $safeFilename . '-' . $uploadedAt->format('YmdHis') . '-' . uniqid() . '.csv';
        $destination = $directory . DIRECTORY_SEPARATOR . $newFilename;
        $stored = is_uploaded_file($sourcePath)
            ? move_uploaded_file($sourcePath, $destination)
            : copy($sourcePath, $destination);
        if ($stored !== true) {
            return self::saveError('Upload failed. Could not save the file.');
        }

        $size = filesize($destination);
        if ($size === false) {
            return self::saveError('Upload failed. Could not read the saved file.');
        }

        return [
            'ok' => true,
            'file' => [
                'name' => $newFilename,
                'size' => $size,
                'createdAt' => $uploadedAt->format(DateTimeInterface::ATOM),
            ],
        ];
    }

    /**
     * @return list<array{EmpID: int, ProjectID: int, DateFrom: string, DateTo: string}>|null
     */
    public static function readFile(string $name, ?string $directory = null): ?array
    {
        $directory ??= dirname(__DIR__) . DIRECTORY_SEPARATOR . 'upload_files';
        $safeName = basename($name);

        if ($safeName !== $name || !str_ends_with(strtolower($safeName), '.csv')) {
            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            $rows = [];
            $isFirst = true;

            while (($columns = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($columns === [null] || count($columns) < 4) {
                    continue;
                }

                $empId = trim((string) $columns[0]);
                $projectId = trim((string) $columns[1]);
                $dateFrom = trim((string) $columns[2]);
                $dateTo = trim((string) $columns[3]);

                if ($isFirst && strcasecmp($empId, 'EmpID') === 0) {
                    $isFirst = false;
                    continue;
                }

                $isFirst = false;

                if ($empId === '' || $projectId === '' || $dateFrom === '') {
                    continue;
                }

                $rows[] = [
                    'EmpID' => (int) $empId,
                    'ProjectID' => (int) $projectId,
                    'DateFrom' => $dateFrom,
                    'DateTo' => $dateTo === '' ? 'NULL' : $dateTo,
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{
     *     pair: list<array{"Employee ID #1": int, "Employee ID #2": int, "Days worked": int}>,
     *     data: list<array{"Employee ID #1": int, "Employee ID #2": int, "Project ID": int, "Days worked": int}>
     * }|null
     */
    public static function findLongestPeriodByProject(
        string $name,
        ?string $directory = null,
        ?DateTimeImmutable $today = null,
    ): ?array {
        $rows = self::readFile($name, $directory);
        if ($rows === null) {
            return null;
        }

        return self::longestPeriodFromRows($rows, $today ?? new DateTimeImmutable('today'));
    }

    /**
     * @param list<array{EmpID: int, ProjectID: int, DateFrom: string, DateTo: string}> $rows
     * @return array{
     *     pair: list<array{"Employee ID #1": int, "Employee ID #2": int, "Days worked": int}>,
     *     data: list<array{"Employee ID #1": int, "Employee ID #2": int, "Project ID": int, "Days worked": int}>
     * }
     */
    private static function longestPeriodFromRows(array $rows, DateTimeImmutable $today): array
    {
        $format = self::dateFormatForRows($rows);
        if ($format === null) {
            return ['pair' => [], 'data' => []];
        }

        $byProject = [];

        foreach ($rows as $row) {
            $from = self::parseDate($row['DateFrom'], $format);
            if ($from === null) {
                continue;
            }

            $to = self::isNullDate($row['DateTo']) ? $today : self::parseDate($row['DateTo'], $format);
            if ($to === null || $to < $from) {
                continue;
            }

            $byProject[$row['ProjectID']][$row['EmpID']][] = [
                'from' => $from,
                'to' => $to,
            ];
        }

        /** @var list<array{emp1: int, emp2: int, projectId: int, days: int}> $overlaps */
        $overlaps = [];

        foreach ($byProject as $projectId => $employees) {
            $mergedByEmployee = [];
            foreach ($employees as $empId => $intervals) {
                $mergedByEmployee[(int) $empId] = self::mergeIntervals($intervals);
            }

            $empIds = array_keys($mergedByEmployee);
            $empCount = count($empIds);

            for ($i = 0; $i < $empCount; $i++) {
                for ($j = $i + 1; $j < $empCount; $j++) {
                    $emp1 = (int) $empIds[$i];
                    $emp2 = (int) $empIds[$j];
                    if ($emp1 > $emp2) {
                        [$emp1, $emp2] = [$emp2, $emp1];
                    }

                    $days = self::overlapDaysForIntervals(
                        $mergedByEmployee[$emp1],
                        $mergedByEmployee[$emp2],
                    );
                    if ($days <= 0) {
                        continue;
                    }

                    $overlaps[] = [
                        'emp1' => $emp1,
                        'emp2' => $emp2,
                        'projectId' => (int) $projectId,
                        'days' => $days,
                    ];
                }
            }
        }

        if ($overlaps === []) {
            return ['pair' => [], 'data' => []];
        }

        /** @var array<string, array{emp1: int, emp2: int, days: int}> $totalsByPair */
        $totalsByPair = [];
        foreach ($overlaps as $overlap) {
            $pairKey = $overlap['emp1'] . '-' . $overlap['emp2'];
            if (!isset($totalsByPair[$pairKey])) {
                $totalsByPair[$pairKey] = [
                    'emp1' => $overlap['emp1'],
                    'emp2' => $overlap['emp2'],
                    'days' => 0,
                ];
            }
            $totalsByPair[$pairKey]['days'] += $overlap['days'];
        }

        $maxDays = max(array_column($totalsByPair, 'days'));
        $winnerKeys = [];
        foreach ($totalsByPair as $pairKey => $total) {
            if ($total['days'] === $maxDays) {
                $winnerKeys[$pairKey] = true;
            }
        }

        $pair = [];
        $data = [];
        $seenPairs = [];
        foreach ($overlaps as $overlap) {
            $pairKey = $overlap['emp1'] . '-' . $overlap['emp2'];
            if (!isset($winnerKeys[$pairKey])) {
                continue;
            }

            if (!isset($seenPairs[$pairKey])) {
                $seenPairs[$pairKey] = true;
                $pair[] = [
                    'Employee ID #1' => $overlap['emp1'],
                    'Employee ID #2' => $overlap['emp2'],
                    'Days worked' => $totalsByPair[$pairKey]['days'],
                ];
            }

            $data[] = [
                'Employee ID #1' => $overlap['emp1'],
                'Employee ID #2' => $overlap['emp2'],
                'Project ID' => $overlap['projectId'],
                'Days worked' => $overlap['days'],
            ];
        }

        usort(
            $pair,
            static fn (array $left, array $right): int => [$left['Employee ID #1'], $left['Employee ID #2']]
                <=> [$right['Employee ID #1'], $right['Employee ID #2']],
        );

        usort(
            $data,
            static fn (array $left, array $right): int => [
                $left['Employee ID #1'],
                $left['Employee ID #2'],
                $left['Project ID'],
            ] <=> [
                $right['Employee ID #1'],
                $right['Employee ID #2'],
                $right['Project ID'],
            ],
        );

        return ['pair' => $pair, 'data' => $data];
    }

    /**
     * @return array{ok: false, error: string, status: int}
     */
    private static function saveError(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'status' => 400];
    }

    private static function uploadedAt(string $filename): ?DateTimeImmutable
    {
        if (!preg_match('/-(\d{14})-[0-9a-f]+\.csv$/i', $filename, $matches)) {
            return null;
        }

        $uploadedAt = DateTimeImmutable::createFromFormat('!YmdHis', $matches[1]);
        if (!$uploadedAt instanceof DateTimeImmutable) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $uploadedAt;
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $value);
        if (!is_string($slug)) {
            return '';
        }

        return trim($slug, '-');
    }

    private static function mimeType(string $path): string|false
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(\FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);

            return is_string($mime) ? $mime : false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 16);
        fclose($handle);
        if ($head === false || $head === '') {
            return false;
        }

        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, '%PDF') => 'application/pdf',
            str_starts_with($head, "PK\x03\x04") => 'application/zip',
            str_starts_with($head, 'MZ') => 'application/x-dosexec',
            str_starts_with($head, "\x7FELF") => 'application/x-executable',
            default => 'text/plain',
        };
    }

    private static function isCsvContent(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    return false;
                }
                if (str_contains($chunk, "\0")) {
                    return false;
                }
            }

            if (!rewind($handle)) {
                return false;
            }

            while (($columns = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($columns === [null]) {
                    continue;
                }

                return true;
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    private static function isNullDate(string $value): bool
    {
        return $value === '' || strcasecmp($value, 'NULL') === 0;
    }

    /**
     * @param list<array{EmpID: int, ProjectID: int, DateFrom: string, DateTo: string}> $rows
     */
    private static function dateFormatForRows(array $rows): ?string
    {
        $samples = [];
        foreach ($rows as $row) {
            if (!self::isNullDate($row['DateFrom'])) {
                $samples[$row['DateFrom']] = true;
            }
            if (!self::isNullDate($row['DateTo'])) {
                $samples[$row['DateTo']] = true;
            }
        }

        if ($samples === []) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            foreach (array_keys($samples) as $sample) {
                if (self::parseDate($sample, $format) === null) {
                    continue 2;
                }
            }

            return $format;
        }

        return null;
    }

    private static function parseDate(string $value, string $format): ?DateTimeImmutable
    {
        if (self::isNullDate($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        if (strcasecmp($date->format($format), $value) !== 0) {
            return null;
        }

        $normalized = DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'));

        return $normalized === false ? null : $normalized;
    }

    /**
     * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable}> $intervals
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable}>
     */
    private static function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort(
            $intervals,
            static function (array $left, array $right): int {
                $fromCompare = $left['from'] <=> $right['from'];

                return $fromCompare !== 0 ? $fromCompare : $left['to'] <=> $right['to'];
            },
        );

        $merged = [$intervals[0]];
        $count = count($intervals);
        for ($i = 1; $i < $count; $i++) {
            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];
            $current = $intervals[$i];

            if ($current['from'] <= $last['to']->modify('+1 day')) {
                if ($current['to'] > $last['to']) {
                    $merged[$lastIndex]['to'] = $current['to'];
                }
                continue;
            }

            $merged[] = $current;
        }

        return $merged;
    }

    /**
     * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable}> $left
     * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable}> $right
     */
    private static function overlapDaysForIntervals(array $left, array $right): int
    {
        $days = 0;
        foreach ($left as $leftInterval) {
            foreach ($right as $rightInterval) {
                $days += self::overlapDays(
                    $leftInterval['from'],
                    $leftInterval['to'],
                    $rightInterval['from'],
                    $rightInterval['to'],
                );
            }
        }

        return $days;
    }

    private static function overlapDays(
        DateTimeImmutable $fromA,
        DateTimeImmutable $toA,
        DateTimeImmutable $fromB,
        DateTimeImmutable $toB,
    ): int {
        $start = $fromA > $fromB ? $fromA : $fromB;
        $end = $toA < $toB ? $toA : $toB;
        if ($end < $start) {
            return 0;
        }

        return $start->diff($end)->days + 1;
    }
}
