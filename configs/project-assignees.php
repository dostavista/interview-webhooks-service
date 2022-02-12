<?php

use Borzo\GithubRepos;
use Borzo\GithubUsers;
use Borzo\GitlabRepos;
use Borzo\GitlabUsers;

function getRandomElement(array $array) {
    return $array[array_rand($array)];
}

function getRandomElementExcept(array $array, array $exceptElement) {
    $newArray = [];
    foreach ($array as $v) {
        if (!in_array($v, $exceptElement, true)) {
            $newArray[] = $v;
        }
    }
    return getRandomElement($newArray);
}

function addRandomTeamLeadReviewer(array $array): array {
    return array_merge($array, [getRandomElementExcept(GithubUsers::TEAMLEAD_REVIEWERS, $array)]);
}

// В этом файле можно настраивать, кто чьи пулл-риквесты ревьюит (автор пулл-риквеста => ревьюеры)
return [
    // GitHub
    GithubRepos::BACKEND => [
        // Все, кто явно не указан в конфиге, попадают на Сидорова и Доромеева
        '*'                     => [GithubUsers::SIDOROV, GithubUsers::DOROMEEV],

        // Backend Team #1
        GithubUsers::SIDOROV    => addRandomTeamLeadReviewer([GithubUsers::SIDOROV]),
        GithubUsers::NECHAEV    => [GithubUsers::SIDOROV, getRandomElement([GithubUsers::PETROVA, GithubUsers::SMIRNOV])],
        GithubUsers::PETROVA    => [GithubUsers::SIDOROV, getRandomElement([GithubUsers::SMIRNOV, GithubUsers::NECHAEV])],
        GithubUsers::SMIRNOV    => [GithubUsers::SIDOROV, getRandomElement([GithubUsers::NECHAEV, GithubUsers::PETROVA])],

        // Backend Team #2
        GithubUsers::POPOV      => addRandomTeamLeadReviewer([GithubUsers::AKAPIEV]),
        GithubUsers::AKAPIEV    => [GithubUsers::POPOV],

        // Backend Team #3
        GithubUsers::KLADENOV   => addRandomTeamLeadReviewer([GithubUsers::KLADENOV]),
        GithubUsers::KOTOVA     => [GithubUsers::KLADENOV, GithubUsers::MIRONOV],
        GithubUsers::MIRONOV    => [GithubUsers::KLADENOV, GithubUsers::BORODIN],
        GithubUsers::BORODIN    => [GithubUsers::KLADENOV, GithubUsers::ULANOV],
        GithubUsers::ULANOV     => [GithubUsers::KLADENOV, GithubUsers::POKROVSKIY],
        GithubUsers::POKROVSKIY => [GithubUsers::KLADENOV, GithubUsers::KOTOVA],
    ],

    GithubRepos::FRONTEND => [
        GithubUsers::IVANOV  => addRandomTeamLeadReviewer([GithubUsers::IVANOV]),
        GithubUsers::VOROBEV => [GithubUsers::IVANOV, GithubUsers::ZAHAROV],
        GithubUsers::PAVLOV  => [GithubUsers::IVANOV, GithubUsers::VOROBEV],
        GithubUsers::ZAHAROV => [GithubUsers::IVANOV, GithubUsers::PAVLOV],

        '*' => [GithubUsers::IVANOV],
    ],

    // GitLab
    GitlabRepos::SERVER_TOOLS => [
        // Infrastructure Team
        GitlabUsers::DOROMEEV => GitlabUsers::DOROMEEV,
        GitlabUsers::TROPAREV => GitlabUsers::DOROMEEV,
        GitlabUsers::DUBOVCEV => GitlabUsers::DOROMEEV,
        GitlabUsers::PUGACHEV => GitlabUsers::DOROMEEV,

        // Все, кто явно не указан в конфиге, попадают на Доромеева
        '*' => GitlabUsers::DOROMEEV,
    ],

    // Если в конфиге для GitLab нет проекта, то он назначается на этого человека
    'gitlab_unknown_to' => ['*' => GitlabUsers::DOROMEEV],
];
