<?php

namespace Borzo;

class Conventions {
    /**
     * Возвращает номер задачи из названия ветки
     */
    public static function getYouTrackIssueIdFromBranch(string $branchName): ?string {
        if (preg_match('/^[a-z]+_([a-z]{2,})?(\d+)_/', $branchName, $match)) {
            $youtrackProject = empty($match[1]) ? 'DV' : strtoupper($match[1]);
            return $youtrackProject . '-' . $match[2];
        }
        return null;
    }

    /**
     * Возвращает номер задачи из текста.
     */
    public static function getYouTrackIssueIdFromText(string $text): ?string {
        /* Примеры легитимных коммитов:
         * - TASK-231321 Удалили сущность заказов
         * - release-manager: TASK-2822 Поправили релиз-менеджер
         * - Внзеапный коммит без задачи
         * - Revert "TASK-1234 Удалили таймспоты (#15619)" (#15676)
         */
        if (preg_match('/\b[A-Z]{2,}-\d+/', $text, $match)) {
            return trim(strtoupper($match[0]));
        }
        return null;
    }
}
