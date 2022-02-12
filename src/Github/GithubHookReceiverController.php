<?php

namespace Borzo\Github;

use Borzo\Conventions;
use Borzo\Fedecks\FedecksTelegramBot;
use Borzo\Super;
use Borzo\VCSHookReceiverControllerAbstract;
use Borzo\YouTrack\YouTrack;
use Borzo\YouTrack\YouTrackStates;
use Borzo\YouTrack\YouTrackTags;

class GithubHookReceiverController extends VCSHookReceiverControllerAbstract {
    protected function getAllowedHostTypes(): array {
        return [static::HOST_FOR_EXTERNAL_API];
    }

    /**
     * Сюда приходят все вебхуки от github.
     * @see https://docs.github.com/en/developers/webhooks-and-events/webhooks/about-webhooks
     */
    public function indexAction() {
        $request = Super::getHttpRequest();

        $type    = $request->header('X-Github-Event');
        $body    = $request->bodyRaw();
        $payload = $request->bodyJson();

        Super::getLog()->info("Got hook with type {$type} and payload {$body}");

        if ($type == 'pull_request') {
            $this->reactToPullRequest($payload);
        }

        // Пересылаем сообщение в телеграм бот
        FedecksTelegramBot::sendGithubPayloadToFedecks($body);
    }

    private function reactToPullRequest(array $payload): void {
        $repoName = $payload['repository']['name'];

        $pullRequest = new GithubPullRequest($payload['pull_request']);

        $pullRequestTitleHtml = htmlspecialchars($pullRequest->title);
        $requestAuthor        = $pullRequest->authorLogin;

        $assignees = Super::getProjectAssignees();

        // Черновик пулл-риквеста
        if ($pullRequest->isDraft) {
            // Добавляем автора пулл-риквеста в Assignees, если нужно
            $assigneesToAssign = $assignees[$repoName][$requestAuthor] ?? null;
            if ($assigneesToAssign && in_array($requestAuthor, $assigneesToAssign)) {
                $pullRequest->addAssignees([$requestAuthor]);
            }

            // Больше с черновиками ничего не делаем
            return;
        }

        $issueId = Conventions::getYouTrackIssueIdFromBranch($payload['pull_request']['head']['ref']);
        if ($issueId === null) {
            $issueId = Conventions::getYouTrackIssueIdFromText($payload['pull_request']['title']);
        }

        $issue = YouTrack::getIssue($issueId);

        $isPullRequestReadyForReview = in_array($payload['action'], ['ready_for_review', 'opened']);

        $addedPullRequestAssignees = [];

        if ($isPullRequestReadyForReview) {
            // Появился пулл-риквест готовый для ревью
            Super::getLog()->info("New pull request #{$pullRequest->number} detected in {$repoName} repository {$pullRequest->url}. Issue {$issueId}");

            // Запишем, кто ревьюит автора
            $pullRequestAssignees = [];
            if (!empty($assignees[$repoName][$requestAuthor])) {
                $pullRequestAssignees = $assignees[$repoName][$requestAuthor];
            } elseif (!empty($assignees[$repoName]['*'])) {
                $pullRequestAssignees = $assignees[$repoName]['*'];
            }

            if (array_diff($pullRequest->getAssigneeLogins(), [$requestAuthor])) {
                // Если в реквест уже кто-то назначен и это не автор, то других ревьюеров не назначаем
                Super::getLog()->info("Pull request #{$pullRequest->number} already has assignees. Issue {$issueId}");
            } elseif ($pullRequestAssignees) {
                $commaSeparatedAssignees = implode(', ', $pullRequestAssignees);
                Super::getLog()->info("Set assignees {$commaSeparatedAssignees} to pull request #{$pullRequest->number} in {$repoName} repository {$pullRequest->url}. Issue {$issueId}");
                $pullRequest->addAssignees($pullRequestAssignees);
                $addedPullRequestAssignees = $pullRequestAssignees;
            } else {
                Super::getLog()->warn("Can not find assignees for pull request #{$pullRequest->number} in {$repoName} repository {$pullRequest->url}. Issue {$issueId}");
            }

            // При создании пулл-реквеста меняем статус задачи на Code Review
            YouTrack::setIssueState(
                $issueId,
                [
                    YouTrackStates::UNSTARTED,
                    YouTrackStates::STARTED,
                    YouTrackStates::REWORK,
                    YouTrackStates::FINISHED,
                ],
                YouTrackStates::CODE_REVIEW
            );
            YouTrack::addCommentToIssue($issueId, "A pull request was made: [{$pullRequestTitleHtml}]({$pullRequest->url})");

            if ($issueId) {
                Github::addCommentToPullRequest(
                    $payload['repository']['owner']['login'],
                    $repoName,
                    $pullRequest->number,
                    'YouTrack: [' . $issueId . '](' . YouTrack::YOUTRACK_URL . '/issue/' . urlencode($issueId) . ')'
                );
            }

            if ($issue) {
                $youtrackIssueIdMdLink           = "[{$issueId}](" . YouTrack::YOUTRACK_URL . '/issue/' . urlencode($issueId) . ')';
                $gitHubCommentLabelByTagTemplate = '`%1$s` label is added because of "**%2$s**" tag in %3$s.';

                // Если задача с тегом Release To Test, то ставим метку 'выложить на тест' в GitHub
                if ($issue->hasTag(YouTrackTags::RELEASE_TO_TEST)) {
                    $pullRequest->addLabels([GithubLabels::RELEASE_TO_TEST]);
                    Github::addCommentToPullRequest(
                        $payload['repository']['owner']['login'],
                        $repoName,
                        $pullRequest->number,
                        sprintf(
                            $gitHubCommentLabelByTagTemplate,
                            /** #1 */ GithubLabels::RELEASE_TO_TEST,
                            /** #2 */ YouTrackTags::RELEASE_TO_TEST,
                            /** #3 */ $youtrackIssueIdMdLink
                        )
                    );
                }

                // Если задача с тегом Need quality assurance, то ставим метку 'не мержить' в GitHub
                if ($issue->hasTag(YouTrackTags::NEED_QUALITY_ASSURANCE)) {
                    $pullRequest->addLabels([GithubLabels::MERGE_FORBIDDEN]);
                    Github::addCommentToPullRequest(
                        $payload['repository']['owner']['login'],
                        $repoName,
                        $pullRequest->number,
                        sprintf(
                            $gitHubCommentLabelByTagTemplate,
                            /** #1 */ GithubLabels::MERGE_FORBIDDEN,
                            /** #2 */ YouTrackTags::NEED_QUALITY_ASSURANCE,
                            /** #3 */ $youtrackIssueIdMdLink
                        )
                    );
                }

                // Если задача с тегом Need Design Review, то ставим метку 'не мержить' в GitHub
                if ($issue->hasTag(YouTrackTags::NEED_DESIGN_REVIEW)) {
                    $pullRequest->addLabels([GithubLabels::MERGE_FORBIDDEN]);
                    Github::addCommentToPullRequest(
                        $payload['repository']['owner']['login'],
                        $repoName,
                        $pullRequest->number,
                        sprintf(
                            $gitHubCommentLabelByTagTemplate,
                            /** #1 */ GithubLabels::MERGE_FORBIDDEN,
                            /** #2 */ YouTrackTags::NEED_DESIGN_REVIEW,
                            /** #3 */ $youtrackIssueIdMdLink
                        )
                    );
                }

                // Если задача Critical, то ставим метку critical в GitHub
                if ($issue->isCritical()) {
                    $pullRequest->addLabels([GithubLabels::CRITICAL]);
                    Github::addCommentToPullRequest(
                        $payload['repository']['owner']['login'],
                        $repoName,
                        $pullRequest->number,
                        '`' . GithubLabels::CRITICAL . "` label is added because of {$youtrackIssueIdMdLink} **critical** state."
                    );
                }
            }
        }

        if ($payload['action'] == 'labeled') {
            $label = $payload['label']['name'] ?? null;
            Super::getLog()->info("Add new label {$label} to pull request #{$pullRequest->number} in {$repoName} repository {$pullRequest->url}. Issue {$issueId}");
            if (mb_strtolower($label) == mb_strtolower(GithubLabels::CHANGES_REQUIRED)) {
                // поставили реквесту «ждём изменений»
                YouTrack::setIssueState(
                    $issueId,
                    [YouTrackStates::CODE_REVIEW],
                    YouTrackStates::REWORK
                );
            } elseif (mb_strtolower($label) == mb_strtolower(GithubLabels::MERGE_FORBIDDEN)) {
                // поставили реквесту «не мержить»
                $comment = "Tag '" . YouTrackTags::WAITING . "' was added to issue because new label '" . GithubLabels::MERGE_FORBIDDEN . "' was added to pull request [{$pullRequestTitleHtml}]({$pullRequest->url}).";

                // Тег проставляем, только если на этот момент его еще нет
                if ($issue && !$issue->hasTag(YouTrackTags::WAITING)) {
                    YouTrack::addTagToIssue($issueId, YouTrackTags::WAITING, $comment);
                }
            }
        }

        if ($payload['action'] == 'unlabeled') {
            $label = $payload['label']['name'] ?? null;
            Super::getLog()->info("Remove label {$label} from pull request #{$pullRequest->number} in {$repoName} repository {$pullRequest->url}. Issue {$issueId}");
            if (mb_strtolower($label) == mb_strtolower(GithubLabels::CHANGES_REQUIRED)) {
                // убрали «ждём изменений»
                YouTrack::setIssueState(
                    $issueId,
                    [
                        YouTrackStates::UNSTARTED,
                        YouTrackStates::STARTED,
                        YouTrackStates::REWORK,
                    ],
                    YouTrackStates::CODE_REVIEW
                );
            } elseif (mb_strtolower($label) == mb_strtolower(GithubLabels::MERGE_FORBIDDEN)) {
                // убрали «не мержить»
                $comment = "Tag '" . YouTrackTags::WAITING . "' was removed from issue because label '" . GithubLabels::MERGE_FORBIDDEN . "' was removed from pull request [{$pullRequestTitleHtml}]({$pullRequest->url}).";

                // Тег удаляем, только если на этот момент тег еще присутствует
                if ($issue && $issue->hasTag(YouTrackTags::WAITING)) {
                    YouTrack::removeTagFromIssue($issueId, YouTrackTags::WAITING, $comment);
                }
            }
        }

        if ($payload['action'] == 'synchronize') {
            Github::markPullRequestIfMergeabilityChanged(
                $payload['repository']['owner']['login'],
                $repoName,
                $pullRequest
            );
        }

        if ($payload['action'] == 'closed' && !empty($payload['pull_request']['merged'])) {
            // замержили реквест
            Super::getLog()->info("Pull request #{$pullRequest->number} was merged in {$repoName} repository {$pullRequest->url}. Issue {$issueId}");

            // Меняем статус задачи на Finished
            YouTrack::setIssueState(
                $issueId,
                [
                    YouTrackStates::STARTED,
                    YouTrackStates::REWORK,
                    YouTrackStates::CODE_REVIEW,
                    YouTrackStates::TESTING,
                    YouTrackStates::WAITING_FOR_RELEASE,
                ],
                YouTrackStates::FINISHED
            );

            // Пишем в задачу комментарий о том, что код поехал на бой
            $comment = "We deployed a bit of code: [{$pullRequestTitleHtml}]({$pullRequest->url})";

            // Пишем в задачу комментарий от разработчика (Release notes)
            $releaseNotesRegexp = '(?:Report|Release notes?|RN)';
            if (preg_match('/(?:#{1,6}\s*' . $releaseNotesRegexp . '[.:]?|\*\*' . $releaseNotesRegexp . '[.:]?\*\*[.:]?)[\r\n\s]+(.+)/ius', $pullRequest->body, $match)) {
                $comment .= "\n\n**Release Notes**\n" . trim($match[1]);
            }

            YouTrack::addCommentToIssue($issueId, $comment);

            if ($issue) {
                // Если задача с тегом Documentation, то пишем в задачу о необходимости обновить документацию
                if ($issue->hasTag(YouTrackTags::DOCUMENTATION)) {
                    $comment = "Don't forget to update Documentation!";
                    YouTrack::addCommentToIssue($issueId, $comment);
                }
            }
        }

        // Тегнем в комментариях людей, подписавшихся на изменения файлов
        if (in_array($payload['action'], ['synchronize', 'opened', 'ready_for_review'], true)) {
            $affectedFiles = Github::getAllAffectedFilenames(
                $payload['repository']['owner']['login'],
                $repoName,
                $payload['pull_request']
            );

            $fileUrls = [];
            foreach ($affectedFiles as $filename) {
                $fileUrls[$filename] = "[{$filename}]" . '(https://github.com/' . urlencode($payload['repository']['owner']['login'])
                    . '/' . urlencode($repoName) . '/pull/' . urlencode($payload['pull_request']['number'])
                    . '/files#diff-' . hash('sha256', $filename) . ')';
            }

            $filesWatchedBy = $this->getFileWatchers($payload['repository']['full_name'], $affectedFiles);
            $watchedUsers   = array_keys($filesWatchedBy);

            // Если пользователь является автором пулл-риквеста или он и так назначен на пулл-риквест, то не надо упоминать его в комментарии
            $watchers = array_diff(
                $watchedUsers,
                array_merge([$requestAuthor], $pullRequest->getAssigneeLogins(), $addedPullRequestAssignees)
            );

            if ($watchers) {
                $comments = Github::getPullRequestComments(
                    $payload['repository']['owner']['login'],
                    $repoName,
                    $pullRequest->number
                );

                foreach ($watchers as $watcher) {
                    $notification = $this->getFileWatchersNotificationString(
                        $watcher,
                        $filesWatchedBy,
                        $fileUrls,
                        $comments
                    );

                    if ($notification) {
                        Github::addCommentToPullRequest(
                            $payload['repository']['owner']['login'],
                            $repoName,
                            $pullRequest->number,
                            $notification
                        );
                    }
                }
            }
        }
    }

    protected function getVcsName(): string {
        return 'git_hub';
    }
}
