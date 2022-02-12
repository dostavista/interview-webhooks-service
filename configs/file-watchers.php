<?php

use Borzo\GithubUsers;
use Borzo\GithubRepos;
use Borzo\GitlabRepos;
use Borzo\GitlabUsers;

// Список отслеживаемых файлов и путей GitHub репозиториев.
// Если файл из списка меняется/добавляется или удаляется в риквесте,
// наблюдатель будет тегнут в комментарии риквеста.
// Имена файлов проверяются частичным вхождением, например, наблюдение за
// "Borzo/Features/ModernApi/" сработает для любого файла в path/to/Borzo/Features/ModernApi/.
// А наблюдение за "ModernApi" сработает за любым файлом, в пути или названии которого присутствует строка ModernApi.
$fileWatcherGitHubConfig = [
    GithubUsers::SIDOROV => [
        GithubRepos::BACKEND => [
            'watch' => [
                'api-schema/common.php',
                'Borzo/Features/AmazonDeliveryTrackingApiConfig.php',
                'Borzo/Features/BackendApiConfig.php',
                'Borzo/Features/BusinessApiConfig.php',
                'Borzo/Features/ClientApiConfig.php',
                'Borzo/Features/CmsModuleApiConfig.php',
                'Borzo/Features/CourierApiConfig.php',
                'Borzo/Features/DocflowApiConfig.php',
                'Borzo/Features/FrontendApiConfig.php',
                'Borzo/Features/OneSApiConfig.php',
                'Borzo/Features/PhoneMaskingApiConfig.php',
                'Borzo/Features/VacsApiConfig.php',
                'Borzo/Features/BackgroundTasksApiConfig.php',
            ],
            'exclude' => [
                'library/Borzo/Features/ModernApi/ApiErrors.php',
                'library/Borzo/Features/ModernApi/ApiParameterErrors.php',
            ],
        ],
    ],
    GithubUsers::PETROVA => [
        GithubRepos::BACKEND => [
            'watch' => [
                'console/build.php',
                'index.php',
            ],
        ],
    ],
    GithubUsers::ULANOV => [
        GithubRepos::BACKEND => [
            'watch' => [
                'Borzo/Features/CourierOnDemand',
                'Borzo/Framework/Cliff',
                'console/examples',
            ],
        ],
    ],
    GithubUsers::AKAPIEV => [
        GithubRepos::BACKEND => [
            'watch' => [
                'Borzo/Core/Geo',
                'Borzo/Features/Robots',
                'Borzo/Features/TimeSlots',
            ],
        ],
    ],
];

// Список отслеживаемых файлов и путей GitLab репозиториев
$fileWatcherGitLabConfig = [
    GitlabUsers::SIDOROV => [
        GitlabRepos::DOCS => [
            'watch' => [
                'backend/',
                'common/',
                'teams/backend-architecture/',
            ],
            'exclude' => [
                'common/postmortems/',
            ],
        ],
        GitlabRepos::INTRANET => [
            'watch' => [
                'Borzo/Onborzing/',
            ],
        ],
        GitlabRepos::PHP_LIBRARY => [
            'watch' => [
                'Borzo/Library/PhpCsFixer/',
            ],
        ],
    ],
];

return [
    'git_hub' => $fileWatcherGitHubConfig,
    'git_lab' => $fileWatcherGitLabConfig,
];
