<?php

declare(strict_types=1);

namespace Stask\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Stask\Libs;

final class LibsTest extends TestCase
{
    public function testVersionReturnsSemver(): void
    {
        self::assertSame('0.1.0', Libs::version());
    }

    public function testListFilesReturnsCsvMetadataAndIgnoresNonCsv(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-list-files-' . uniqid('', true);
        mkdir($directory);

        try {
            $csvPath = $directory . DIRECTORY_SEPARATOR . 'sample.csv';
            file_put_contents($csvPath, "143,12,2013-11-01,2014-01-05\n");
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'notes.txt', 'ignore me');
            mkdir($directory . DIRECTORY_SEPARATOR . 'nested.csv');

            $files = Libs::listFiles($directory);

            self::assertCount(1, $files);
            self::assertSame('sample.csv', $files[0]['name']);
            self::assertSame(filesize($csvPath), $files[0]['size']);
            self::assertInstanceOf(
                DateTimeImmutable::class,
                DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $files[0]['createdAt']),
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testListFilesUsesUploadStampInsteadOfFilemtime(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-list-stamp-' . uniqid('', true);
        mkdir($directory);

        try {
            $name = 'Report-20200102030405-abc.csv';
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            file_put_contents($path, "143,12,2013-11-01,2014-01-05\n");
            $mtime = (new DateTimeImmutable('2024-06-01 12:00:00'))->getTimestamp();
            touch($path, $mtime);

            $files = Libs::listFiles($directory);

            $uploadedAt = DateTimeImmutable::createFromFormat('!YmdHis', '20200102030405');
            self::assertInstanceOf(DateTimeImmutable::class, $uploadedAt);
            self::assertCount(1, $files);
            self::assertSame($name, $files[0]['name']);
            self::assertSame($uploadedAt->format(DateTimeInterface::ATOM), $files[0]['createdAt']);
            self::assertNotSame(
                (new DateTimeImmutable())->setTimestamp((int) filemtime($path))->format(DateTimeInterface::ATOM),
                $files[0]['createdAt'],
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testListFilesReturnsNewestUploadFirst(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-list-order-' . uniqid('', true);
        mkdir($directory);

        try {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'Older-20200101000000-aaa.csv', "143,12,2013-11-01,2014-01-05\n");
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'Newer-20240101000000-bbb.csv', "143,12,2013-11-01,2014-01-05\n");
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'Same-Hour-B-20240101000000-ccc.csv', "143,12,2013-11-01,2014-01-05\n");

            $files = Libs::listFiles($directory);

            self::assertSame(
                [
                    'Newer-20240101000000-bbb.csv',
                    'Same-Hour-B-20240101000000-ccc.csv',
                    'Older-20200101000000-aaa.csv',
                ],
                array_column($files, 'name'),
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testListFilesReturnsEmptyArrayWhenDirectoryIsMissing(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-missing-' . uniqid('', true);

        self::assertSame([], Libs::listFiles($directory));
    }

    public function testReadFileParsesRowsWithoutHeader(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-read-file-' . uniqid('', true);
        mkdir($directory);

        try {
            file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'sample.csv',
                "143,12,2013-11-01,2014-01-05\n218,12,2013-12-01,2014-02-01\n",
            );

            $rows = Libs::readFile('sample.csv', $directory);

            self::assertSame(
                [
                    ['EmpID' => 143, 'ProjectID' => 12, 'DateFrom' => '2013-11-01', 'DateTo' => '2014-01-05'],
                    ['EmpID' => 218, 'ProjectID' => 12, 'DateFrom' => '2013-12-01', 'DateTo' => '2014-02-01'],
                ],
                $rows,
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testReadFileSkipsHeaderTrimsSpacesAndKeepsNullDateTo(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-read-header-' . uniqid('', true);
        mkdir($directory);

        try {
            file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'header.csv',
                "EmpID, ProjectID, DateFrom, DateTo\n143, 50, 2024-05-01, 2024-06-01\n218, 50, 2024-04-01, NULL\n",
            );

            $rows = Libs::readFile('header.csv', $directory);

            self::assertSame(
                [
                    ['EmpID' => 143, 'ProjectID' => 50, 'DateFrom' => '2024-05-01', 'DateTo' => '2024-06-01'],
                    ['EmpID' => 218, 'ProjectID' => 50, 'DateFrom' => '2024-04-01', 'DateTo' => 'NULL'],
                ],
                $rows,
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testReadFileReturnsNullWhenFileIsMissing(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-read-missing-' . uniqid('', true);
        mkdir($directory);

        try {
            self::assertNull(Libs::readFile('missing.csv', $directory));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testFindLongestPeriodByProjectReturnsBasicOverlap(): void
    {
        $result = $this->longestFromCsv("143,12,2013-11-01,2014-01-05\n218,12,2013-12-01,2014-02-01\n");

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Days worked' => 36],
                ],
                'data' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Project ID' => 12, 'Days worked' => 36],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectSumsDaysAcrossProjects(): void
    {
        $result = $this->longestFromCsv(
            "1,100,2020-01-01,2020-01-21\n"
            . "2,100,2020-01-11,2020-02-01\n"
            . "1,200,2020-03-01,2020-03-21\n"
            . "2,200,2020-03-06,2020-04-01\n"
            . "1,300,2020-05-01,2020-05-31\n"
            . "3,300,2020-05-11,2020-06-01\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Days worked' => 27],
                ],
                'data' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 100, 'Days worked' => 11],
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 200, 'Days worked' => 16],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectIncludesAllJointProjectsOfWinner(): void
    {
        $result = $this->longestFromCsv(
            "1,10,2020-01-01,2020-01-31\n"
            . "2,10,2020-01-01,2020-01-31\n"
            . "1,20,2020-03-01,2020-03-06\n"
            . "2,20,2020-03-01,2020-03-06\n"
            . "3,30,2020-02-01,2020-02-21\n"
            . "4,30,2020-02-01,2020-02-21\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Days worked' => 37],
                ],
                'data' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 10, 'Days worked' => 31],
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 20, 'Days worked' => 6],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectBoundsNullDateTo(): void
    {
        $result = $this->longestFromCsv("143,50,2024-05-01,2024-06-01\n218,50,2024-04-01,NULL\n");

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Days worked' => 32],
                ],
                'data' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Project ID' => 50, 'Days worked' => 32],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectTreatsNullDateToAsToday(): void
    {
        $today = new DateTimeImmutable('2026-10-20');
        $result = $this->longestFromCsv(
            "1,77,2026-10-10,NULL\n2,77,2026-10-10,NULL\n",
            $today,
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Days worked' => 11],
                ],
                'data' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 77, 'Days worked' => 11],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectPicksLongestPairAmongThreeEmployees(): void
    {
        $result = $this->longestFromCsv(
            "1,90,2021-01-01,2021-03-01\n"
            . "2,90,2021-01-01,2021-01-11\n"
            . "3,90,2021-01-10,2021-02-01\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 3, 'Days worked' => 23],
                ],
                'data' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 3, 'Project ID' => 90, 'Days worked' => 23],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectCountsAdjacentBoundaryAsOneDay(): void
    {
        $result = $this->longestFromCsv("10,5,2021-01-01,2021-01-10\n20,5,2021-01-10,2021-01-31\n");

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 10, 'Employee ID #2' => 20, 'Days worked' => 1],
                ],
                'data' => [
                    ['Employee ID #1' => 10, 'Employee ID #2' => 20, 'Project ID' => 5, 'Days worked' => 1],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectMergesSameEmployeeRows(): void
    {
        $result = $this->longestFromCsv(
            "5,33,2022-01-01,2022-01-20\n"
            . "5,33,2022-03-01,2022-03-15\n"
            . "9,33,2022-01-15,2022-01-20\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 5, 'Employee ID #2' => 9, 'Days worked' => 6],
                ],
                'data' => [
                    ['Employee ID #1' => 5, 'Employee ID #2' => 9, 'Project ID' => 33, 'Days worked' => 6],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectMergesOverlappingEmployeeIntervals(): void
    {
        $result = $this->longestFromCsv(
            "1,80,2026-10-20,2026-10-25\n"
            . "1,80,2026-10-15,2026-10-22\n"
            . "2,80,2026-10-15,2026-10-25\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Days worked' => 11],
                ],
                'data' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 80, 'Days worked' => 11],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectReturnsAllTiedPairs(): void
    {
        $result = $this->longestFromCsv(
            "1,1,2020-01-01,2020-01-11\n"
            . "2,1,2020-01-01,2020-01-11\n"
            . "3,2,2020-02-01,2020-02-11\n"
            . "4,2,2020-02-01,2020-02-11\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Days worked' => 11],
                    ['Employee ID #1' => 3, 'Employee ID #2' => 4, 'Days worked' => 11],
                ],
                'data' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 1, 'Days worked' => 11],
                    ['Employee ID #1' => 3, 'Employee ID #2' => 4, 'Project ID' => 2, 'Days worked' => 11],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectNormalizesHigherIdFirst(): void
    {
        $result = $this->longestFromCsv("218,12,2013-12-01,2014-02-01\n143,12,2013-11-01,2014-01-05\n");

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Days worked' => 36],
                ],
                'data' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Project ID' => 12, 'Days worked' => 36],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectReturnsEmptyWhenThereIsNoOverlap(): void
    {
        $result = $this->longestFromCsv(
            "143,12,2013-11-01,2014-01-05\n"
            . "218,10,2012-05-16,NULL\n"
            . "143,10,2009-01-01,2011-04-27\n",
        );

        self::assertSame(['pair' => [], 'data' => []], $result);
    }

    public function testFindLongestPeriodByProjectAcceptsOneNumericFormatPerFile(): void
    {
        $dayMonthYear = $this->longestFromCsv(
            "143,12,01.11.2013,05.01.2014\n218,12,01.12.2013,01.02.2014\n",
        );
        $yearFirst = $this->longestFromCsv(
            "143,12,2013/11/01,2014/01/05\n218,12,2013/12/01,2014/02/01\n",
        );

        $expected = [
            'pair' => [
                ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Days worked' => 36],
            ],
            'data' => [
                ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Project ID' => 12, 'Days worked' => 36],
            ],
        ];
        self::assertSame($expected, $dayMonthYear);
        self::assertSame($expected, $yearFirst);
    }

    public function testFindLongestPeriodByProjectUsesDayFirstWhenADayExceedsTwelve(): void
    {
        $result = $this->longestFromCsv(
            "143,12,01/11/2013,05/01/2014\n218,12,15/12/2013,01/02/2014\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Days worked' => 22],
                ],
                'data' => [
                    ['Employee ID #1' => 143, 'Employee ID #2' => 218, 'Project ID' => 12, 'Days worked' => 22],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectUsesMonthFirstWhenAMonthDayExceedsTwelve(): void
    {
        $result = $this->longestFromCsv(
            "10,5,01/15/2021,01/20/2021\n20,5,01/18/2021,01/25/2021\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 10, 'Employee ID #2' => 20, 'Days worked' => 3],
                ],
                'data' => [
                    ['Employee ID #1' => 10, 'Employee ID #2' => 20, 'Project ID' => 5, 'Days worked' => 3],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectPrefersDayFirstWhenTheFileIsAmbiguous(): void
    {
        $result = $this->longestFromCsv(
            "1,1,01/02/2020,03/04/2020\n2,1,01/03/2020,02/03/2020\n",
        );

        self::assertSame(
            [
                'pair' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Days worked' => 2],
                ],
                'data' => [
                    ['Employee ID #1' => 1, 'Employee ID #2' => 2, 'Project ID' => 1, 'Days worked' => 2],
                ],
            ],
            $result,
        );
    }

    public function testFindLongestPeriodByProjectReturnsEmptyWhenDateFormatsAreMixed(): void
    {
        $result = $this->longestFromCsv(
            "143,12,2013-11-01,2014-01-05\n218,12,01.12.2013,01.02.2014\n",
        );

        self::assertSame(['pair' => [], 'data' => []], $result);
    }

    public function testFindLongestPeriodByProjectReturnsEmptyWhenADateIsInvalid(): void
    {
        $result = $this->longestFromCsv(
            "143,12,2013-11-01,2014-02-31\n218,12,2013-12-01,2014-02-01\n",
        );

        self::assertSame(['pair' => [], 'data' => []], $result);
    }

    public function testSaveCsvStoresSluggedNameWithUniqid(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-save-csv-' . uniqid('', true);
        mkdir($directory);
        $source = $directory . DIRECTORY_SEPARATOR . 'source.csv';
        $contents = "143,12,2013-11-01,2014-01-05\n";

        try {
            file_put_contents($source, $contents);

            $result = Libs::saveCsv($source, 'My Report.csv', $directory);

            self::assertTrue($result['ok']);
            self::assertMatchesRegularExpression('/^My-Report-\d{14}-[0-9a-f]+\.csv$/', $result['file']['name']);
            self::assertSame(1, preg_match('/^My-Report-(\d{14})-[0-9a-f]+\.csv$/', $result['file']['name'], $matches));
            $uploadedAt = DateTimeImmutable::createFromFormat('!YmdHis', $matches[1]);
            self::assertInstanceOf(DateTimeImmutable::class, $uploadedAt);
            self::assertSame($uploadedAt->format(DateTimeInterface::ATOM), $result['file']['createdAt']);
            self::assertSame(strlen($contents), $result['file']['size']);
            self::assertSame(
                $contents,
                file_get_contents($directory . DIRECTORY_SEPARATOR . $result['file']['name']),
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testSaveCsvRejectsNonCsvExtension(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-save-txt-' . uniqid('', true);
        mkdir($directory);
        $source = $directory . DIRECTORY_SEPARATOR . 'notes.txt';

        try {
            file_put_contents($source, "143,12,2013-11-01,2014-01-05\n");

            $result = Libs::saveCsv($source, 'notes.txt', $directory);

            self::assertFalse($result['ok']);
            self::assertSame(400, $result['status']);
            self::assertSame('Only CSV files are allowed.', $result['error']);
            self::assertSame(['.', '..', 'notes.txt'], scandir($directory));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testSaveCsvRejectsBinaryFileWithCsvExtension(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-save-binary-' . uniqid('', true);
        mkdir($directory);
        $source = $directory . DIRECTORY_SEPARATOR . 'image.csv';

        try {
            file_put_contents($source, "\x89PNG\r\n\x1a\n\0not-a-csv");

            $result = Libs::saveCsv($source, 'image.csv', $directory);

            self::assertFalse($result['ok']);
            self::assertSame(400, $result['status']);
            self::assertSame('The file is not a valid CSV.', $result['error']);
            self::assertSame(['.', '..', 'image.csv'], scandir($directory));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testFindLongestPeriodByProjectReturnsNullWhenFileIsMissing(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-longest-missing-' . uniqid('', true);
        mkdir($directory);

        try {
            self::assertNull(Libs::findLongestPeriodByProject('missing.csv', $directory));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * @return array{
     *     pair: list<array{"Employee ID #1": int, "Employee ID #2": int, "Days worked": int}>,
     *     data: list<array{"Employee ID #1": int, "Employee ID #2": int, "Project ID": int, "Days worked": int}>
     * }|null
     */
    private function longestFromCsv(string $contents, ?DateTimeImmutable $today = null): ?array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stask-longest-' . uniqid('', true);
        mkdir($directory);

        try {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'sample.csv', $contents);

            return Libs::findLongestPeriodByProject('sample.csv', $directory, $today);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
