<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TeampassClasses\Language\Language;

require_once __DIR__ . '/../../app/vendor/sergeytsalkov/meekrodb/db.class.php';
require_once __DIR__ . '/../../app/sources/logs_filter_logic.php';

/** Test log filtering decisions without a database or the SQLite extension. */
class LogsFilterLogicTest extends TestCase
{
    /** Keep selectable fields aligned with the visible search choices. */
    public function testColumnSelectionOnlyAcceptsTheUiAllowList(): void
    {
        foreach (['i.id', 'i.label', 't.title', 'l.action'] as $column) {
            self::assertSame([$column], getItemLogSearchColumns($column));
        }
        self::assertSame(['u.login', 'u.name', 'u.lastname'], getItemLogSearchColumns('u.login'));
        foreach ([null, [], 'i.label) OR 1=1 --', 'u.name', 'unknown', 'l.date', 'l.raison', 't.personal_folder'] as $invalid) {
            self::assertSame(getItemLogSearchColumns('all'), getItemLogSearchColumns($invalid));
        }
        self::assertNotContains('l.date', getItemLogSearchColumns('all'));
    }

    /** Reject invalid scopes before any deletion is attempted. */
    public function testInvalidOrUnresolvablePurgeScopeIsRejected(): void
    {
        foreach (['unknown', 'kb', 'authentication_lockouts'] as $tab) {
            self::assertNull(buildLogsPurgeFilter($tab, 100, 200, -1, 'all'));
        }
        foreach ([0, -2] as $userId) {
            self::assertNull(buildLogsPurgeFilter('items', 100, 200, $userId, 'all'));
        }
        self::assertNull(buildLogsPurgeFilter('items', 200, 100, -1, 'all'));
        self::assertNull(buildLogsPurgeFilter('items', 100, 200, -1, 'unknown'));
        self::assertNull(buildLogsPurgeFilter('errors', 100, 200, -1, 'at_copy'));
        self::assertNull(buildLogsPurgeFilter('failed', 100, 200, 42, 'all'));
        self::assertNull(buildLogsPurgeFilter('failed', 100, 200, 42, 'all', ''));
    }

    /** Date ranges include the full last day, including daylight-saving transitions. */
    public function testDateRangeIncludesTheLastDayAndHandlesDst(): void
    {
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Paris');
            foreach (['2026-09-08' => 24, '2026-03-29' => 23, '2026-10-25' => 25] as $day => $hours) {
                [$start, $end] = getLogsPurgeDateRange($day, $day);
                self::assertSame($hours * 3600, $end - $start);
                self::assertSame($day . ' 23:59:59', date('Y-m-d H:i:s', $end - 1));
            }
        } finally {
            date_default_timezone_set($timezone);
        }
        foreach ([['', ''], [null, null], [[], '2026-09-08'], ['2026-02-30', '2026-03-01'],
            ['2026-09-09', '2026-09-08'], ['2026-9-8', '2026-09-08'], ['today', 'tomorrow'], ["2026-09-08\0", '2026-09-08']] as [$start, $end]) {
            self::assertNull(getLogsPurgeDateRange($start, $end));
        }
    }

    /** Translated labels and stored codes remain searchable when wording changes. */
    public function testActionSearchUsesTheLanguageCatalogAndStoredCodes(): void
    {
        foreach (['english', 'french', 'arabic'] as $language) {
            $lang = new Language($language, __DIR__ . '/../../app/includes/language');
            foreach (['at_creation', 'at_shown', 'at_manual', 'at_access', 'at_password_copied', 'at_password_shown_edit_form'] as $action) {
                self::assertContains($action, getItemLogActionSearchCodes((string) $lang->get($action), $lang));
                self::assertContains($action, getItemLogActionSearchCodes(strtoupper($action), $lang));
            }
            self::assertSame([], getItemLogActionSearchCodes('no-such-action-5368', $lang));
        }
    }

    /** Source assertions check wiring; the helpers' behavior is tested directly. */
    public function testHandlersUseTheTestedFilteringHelpers(): void
    {
        $dataTable = file_get_contents(__DIR__ . '/../../app/sources/logs.datatables.php');
        self::assertStringContainsString("require_once __DIR__ . '/logs_filter_logic.php';", $dataTable);
        self::assertStringContainsString('buildItemLogSearchFilter(', $dataTable);
        self::assertStringContainsString('buildLogsPurgeFilter(', file_get_contents(__DIR__ . '/../../app/sources/utilities.queries.php'));
    }
}
