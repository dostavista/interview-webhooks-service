<?php

use Borzo\Github\GithubHookReceiverController;
use Borzo\Gitlab\GitlabHookReceiverController;

switch ($_SERVER['REQUEST_URI']) {
    case '/github':
        (new GithubHookReceiverController())->indexAction();
        break;
    case '/gitlab':
        (new GitlabHookReceiverController())->indexAction();
        break;
}
