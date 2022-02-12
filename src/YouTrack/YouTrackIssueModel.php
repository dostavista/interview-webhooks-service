<?php

namespace Borzo\YouTrack;

class YouTrackIssueModel {
    /** @var string */
    public string $priority;

    /** @var string */
    public string $state;

    /** @var string[] */
    public array $tags = [];

    /** @var int|null */
    public ?int $codeReviewIteration;

    public function isCritical(): bool {
        return $this->priority == YouTrackPriorities::CRITICAL;
    }

    public function hasTag(string $tag): bool {
        return in_array($tag, $this->tags);
    }
}
