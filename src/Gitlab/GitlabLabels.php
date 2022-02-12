<?php

namespace Borzo\Gitlab;

class GitlabLabels {
    public const CHANGES_REQUIRED = 'ждём изменений';
    public const MERGE_FORBIDDEN  = 'не мержить';
    public const RELEASE_TO_TEST  = 'выложить на тест';
    public const REBASE_REQUIRED  = 'нужен rebase';
}
