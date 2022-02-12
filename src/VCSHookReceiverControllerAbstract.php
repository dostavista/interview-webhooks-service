<?php

namespace Borzo;

abstract class VCSHookReceiverControllerAbstract extends WebhooksControllerAbstract {
    private ?array $fileWatchers = null;

    /**
     * Возвращает название системы контроля версий
     */
    abstract protected function getVcsName(): string;

    private function loadFileWatchers(): array {
        if ($this->fileWatchers === null) {
            $config = include __DIR__ . '/../configs/file-watchers.php';
            $this->fileWatchers = $config[$this->getVcsName()] ?? [];
        }
        return $this->fileWatchers;
    }

    /**
     * Возвращает список пользователей (username'ы), подписанных
     * на отслеживание хотя бы одного из указанных файлов в репозитории.
     * Пути проверяются частичным вхождением.
     *
     * @param string[] $changedFiles
     * @return string[][] [github username => [file1, file2, ...], ...]
     */
    protected function getFileWatchers(string $repoName, array $changedFiles): array {
        foreach ($changedFiles as $key => $changedFile) {
            $changedFiles[$key] = mb_strtolower($changedFile);
        }

        $users    = [];
        $watched  = [];
        $excluded = [];
        foreach ($this->loadFileWatchers() as $user => $userConfigs) {
            $config = $userConfigs[$repoName] ?? null;
            if ($config === null) {
                continue;
            }

            $users[]         = $user;
            $watched[$user]  = [];
            $excluded[$user] = [];

            foreach ($config['watch'] as $watchedFile) {
                $watched[$user][] = mb_strtolower($watchedFile);
            }

            foreach ($config['exclude'] ?? [] as $excludedFile) {
                $excluded[$user][] = mb_strtolower($excludedFile);
            }
        }

        $watchedBy = [];
        foreach ($users as $user) {
            $watchedList = [];

            foreach ($changedFiles as $changedFile) {
                // Если в списке watch указан конкретный файл, то его добавляем в список, несмотря на exclude
                if ($this->isFileMatch($changedFile, $watched[$user])) {
                    $watchedList[] = $changedFile;
                    continue;
                }

                // В остальных случаях учитываем exclude
                if (
                    $this->isAnySubPathMatch($changedFile, $watched[$user])
                    && !$this->isAnySubPathMatch($changedFile, $excluded[$user])
                ) {
                    $watchedList[] = $changedFile;
                    continue;
                }
            }

            if ($watchedList) {
                $watchedBy[$user] = $watchedList;
            }
        }

        return $watchedBy;
    }

    private function isFileMatch(string $filename, array $subPathList): bool {
        foreach ($subPathList as $subPath) {
            if (str_ends_with($filename, $subPath)) {
                return true;
            }
        }

        return false;
    }

    private function isAnySubPathMatch(string $filename, array $subPathList): bool {
        foreach ($subPathList as $subPath) {
            if (str_contains($filename, $subPath)) {
                return true;
            }
        }

        return false;
    }

    protected function getFileWatchersNotificationString(string $watcher, array $filesWatchedBy, array $fileUrls, array $comments, string $lineBreaker = "\n"): ?string {
        $watcherFiles = $filesWatchedBy[$watcher];

        $fileUrlsNeedNotify = [];
        foreach ($fileUrls as $filename => $fileUrl) {
            if (in_array(strtolower($filename), $watcherFiles)) {
                $fileUrlsNeedNotify[$filename] = $fileUrl;
            }
        }

        $notifyHeader = "@{$watcher}, there are interesting files affected:$lineBreaker";

        // Оставим в списке файлов только те, по которым еще не тегнули ранее наблюдателя в комментариях
        foreach ($comments as $comment) {
            if (!str_contains($comment, $notifyHeader)) {
                // Пропускаем комментарии не относящиеся к уведомлению наблюдателя об измененных файлах
                continue;
            }

            // Исключаем уже упомянутые файлы
            foreach (array_keys($fileUrlsNeedNotify) as $filename) {
                if (str_contains($comment, $filename)) {
                    unset($fileUrlsNeedNotify[$filename]);
                }
            }

            if (empty($fileUrlsNeedNotify)) {
                break;
            }
        }

        if (empty($fileUrlsNeedNotify)) {
            return null;
        }

        return $notifyHeader . implode($lineBreaker, $fileUrlsNeedNotify);
    }
}
