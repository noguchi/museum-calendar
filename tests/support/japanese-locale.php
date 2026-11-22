<?php
/**
 * サイトを日本語にしてコールバックを実行する関数を返す。
 * テスト環境のサイトは en_US。日本語の曜日名と月名は ics-fixture プラグインが用意する。
 */
return static function (callable $render): string {
    if (!switch_to_locale('ja')) {
        throw new RuntimeException('The test site could not switch to Japanese.');
    }
    try {
        return $render();
    } finally {
        restore_previous_locale();
    }
};
