<?php

declare(strict_types=1);

namespace Mosl\OpenSignBridgeBundle\Command;

use GuzzleHttp\ClientInterface;
use Rollerworks\Component\PasswordStrength\Validator\Constraints\PasswordStrength;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'opensignb:opensign:setup',
    description: 'Bootstrap OpenSign: complete system setup (user, tenant, organization, team, profile).',
)]
class OpenSignSetupCommand extends Command
{
    /**
     * The literal placeholder shipped in config/opensign_setup.yaml. Running
     * setup with this value would provision the OpenSign admin account with a
     * password anyone who has read this bundle's source already knows.
     */
    private const string PLACEHOLDER_PASSWORD = 'CHANGE-ME-BEFORE-RUNNING-opensignb-opensign-setup';

    private ClientInterface $client;

    private string $appId;

    private string $masterKey;

    private string $apiUrl;

    private string $rootPath;

    public function __construct(
        string $rootPath,
        ClientInterface $client,
        ?string $appId = null,
        ?string $masterKey = null,
        ?string $apiUrl = null
    ) {
        $this->rootPath = $rootPath;
        $this->client = $client;
        $this->appId = $appId ?? '';
        $this->masterKey = $masterKey ?? '';
        $this->apiUrl = rtrim($apiUrl ?? '', '/');

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OpenSign Full Bootstrap Setup');

        $configPath = $this->rootPath . '/config/opensign_setup.yaml';
        if (! file_exists($configPath)) {
            $io->error(sprintf('Configuration file not found: %s', $configPath));

            return Command::FAILURE;
        }

        /** @var array<string, array<string, string|bool>> $config */
        $config = Yaml::parseFile($configPath);

        // Yaml::parseFile never resolves %env()% — this file is committed, so the
        // password can't live in it directly. OPENSIGN_SETUP_ADMIN_PASSWORD lets a
        // real secret (Doppler, etc.) override the YAML placeholder at run time.
        $envPassword = $_ENV['OPENSIGN_SETUP_ADMIN_PASSWORD'] ?? getenv('OPENSIGN_SETUP_ADMIN_PASSWORD');
        $password = is_string($envPassword) && $envPassword !== '' ? $envPassword : ($config['admin']['password'] ?? '');
        if (! is_string($password)) {
            $io->error('config/opensign_setup.yaml: admin.password must be a string.');

            return Command::FAILURE;
        }

        if (hash_equals(self::PLACEHOLDER_PASSWORD, $password)) {
            $io->error(
                'config/opensign_setup.yaml still has the placeholder admin password. '
                . 'Edit admin.password before running this command against any real OpenSign instance.'
            );

            return Command::FAILURE;
        }

        $validator = Validation::createValidator();
        $violations = $validator->validate($password, new PasswordStrength(minLength: 12, minStrength: 4));
        if (count($violations) > 0) {
            $io->error('config/opensign_setup.yaml: admin.password is too weak for an OpenSign admin account.');
            foreach ($violations as $violation) {
                $io->text('- ' . $violation->getMessage());
            }

            return Command::FAILURE;
        }

        $config['admin']['password'] = $password;

        try {
            $headers = [
                'X-Parse-Application-Id' => $this->appId,
                'X-Parse-Master-Key' => $this->masterKey,
                'Content-Type' => 'application/json',
            ];

            // 1. Create Admin User
            $io->section('1. Creating System Admin User');
            $response = $this->client->request('POST', $this->apiUrl . '/users', [
                'headers' => $headers,
                'json' => [
                    'username' => $config['admin']['username'],
                    'password' => $config['admin']['password'],
                    'email' => $config['admin']['email'],
                    'name' => $config['admin']['name'],
                    'phone' => $config['admin']['phone'],
                ],
                'http_errors' => false,
            ]);

            $userData = json_decode($response->getBody()->getContents(), true);
            $userId = is_array($userData) && isset($userData['objectId']) && is_string(
                $userData['objectId']
            ) ? $userData['objectId'] : null;

            if (! $userId && is_array($userData) && isset($userData['code']) && $userData['code'] === 202) {
                $io->note('User already exists, attempting login to get token...');
                $response = $this->client->request('POST', $this->apiUrl . '/login', [
                    'headers' => $headers,
                    'json' => [
                        'username' => $config['admin']['username'],
                        'password' => $config['admin']['password'],
                    ],
                ]);
                $loginData = json_decode($response->getBody()->getContents(), true);
                if (
                    is_array($loginData) && isset($loginData['objectId']) && is_string(
                        $loginData['objectId']
                    ) && isset($loginData['sessionToken']) && is_string($loginData['sessionToken'])
                ) {
                    $userId = $loginData['objectId'];
                    $sessionToken = $loginData['sessionToken'];
                } else {
                    throw new \RuntimeException('Failed to login existing user');
                }
            } else {
                $sessionToken = is_array($userData) && isset($userData['sessionToken']) && is_string(
                    $userData['sessionToken']
                ) ? $userData['sessionToken'] : '';
            }

            if (! $userId) {
                throw new \RuntimeException('Failed to create or login user');
            }

            $io->success('User ID: ' . $userId);

            // 2. Create Tenant
            $io->section('2. Creating Tenant');
            $response = $this->client->request('POST', $this->apiUrl . '/classes/partners_Tenant', [
                'headers' => $headers,
                'json' => [
                    'TenantName' => $config['admin']['company'],
                    'companyName' => $config['admin']['company'],
                    'EmailAddress' => $config['admin']['email'],
                    'ContactNumber' => $config['admin']['phone'],
                    'IsActive' => true,
                    'UserId' => [
                        '__type' => 'Pointer',
                        'className' => '_User',
                        'objectId' => $userId,
                    ],
                    'CreatedBy' => [
                        '__type' => 'Pointer',
                        'className' => '_User',
                        'objectId' => $userId,
                    ],
                ],
            ]);
            $tenantData = json_decode($response->getBody()->getContents(), true);
            if (! is_array($tenantData) || ! isset($tenantData['objectId']) || ! is_string($tenantData['objectId'])) {
                throw new \RuntimeException('Failed to create Tenant');
            }
            $tenantId = $tenantData['objectId'];
            $io->success('Tenant ID: ' . $tenantId);

            // 3. Create Organizations
            $io->section('3. Creating Organization');
            $response = $this->client->request('POST', $this->apiUrl . '/classes/contracts_Organizations', [
                'headers' => $headers,
                'json' => [
                    'Name' => $config['admin']['company'],
                    'IsActive' => true,
                    'CreatedBy' => [
                        '__type' => 'Pointer',
                        'className' => '_User',
                        'objectId' => $userId,
                    ],
                    'TenantId' => [
                        '__type' => 'Pointer',
                        'className' => 'partners_Tenant',
                        'objectId' => $tenantId,
                    ],
                ],
            ]);
            $orgData = json_decode($response->getBody()->getContents(), true);
            if (! is_array($orgData) || ! isset($orgData['objectId']) || ! is_string($orgData['objectId'])) {
                throw new \RuntimeException('Failed to create Organization');
            }
            $orgId = $orgData['objectId'];
            $io->success('Organization ID: ' . $orgId);

            // 4. Create Team (All Users)
            $io->section('4. Creating Team');
            $response = $this->client->request('POST', $this->apiUrl . '/classes/contracts_Teams', [
                'headers' => $headers,
                'json' => [
                    'Name' => 'All Users',
                    'IsActive' => true,
                    'OrganizationId' => [
                        '__type' => 'Pointer',
                        'className' => 'contracts_Organizations',
                        'objectId' => $orgId,
                    ],
                ],
            ]);
            $teamData = json_decode($response->getBody()->getContents(), true);
            if (! is_array($teamData) || ! isset($teamData['objectId']) || ! is_string($teamData['objectId'])) {
                throw new \RuntimeException('Failed to create Team');
            }
            $teamId = $teamData['objectId'];

            // Self link for ancestors
            $this->client->request('PUT', $this->apiUrl . '/classes/contracts_Teams/' . $teamId, [
                'headers' => $headers,
                'json' => [
                    'Ancestors' => [
                        [
                            '__type' => 'Pointer',
                            'className' => 'contracts_Teams',
                            'objectId' => $teamId,
                        ],
                    ],
                ],
            ]);
            $io->success('Team ID: ' . $teamId);

            // 5. Create Profile (contracts_Users)
            $io->section('5. Creating Profile (contracts_Users)');
            $response = $this->client->request('POST', $this->apiUrl . '/classes/contracts_Users', [
                'headers' => $headers,
                'json' => [
                    'Name' => $config['admin']['name'],
                    'Email' => $config['admin']['email'],
                    'Phone' => $config['admin']['phone'],
                    'Company' => $config['admin']['company'],
                    'JobTitle' => $config['admin']['job_title'],
                    'UserRole' => 'contracts_Admin',
                    'Timezone' => $config['admin']['timezone'],
                    'UserId' => [
                        '__type' => 'Pointer',
                        'className' => '_User',
                        'objectId' => $userId,
                    ],
                    'TenantId' => [
                        '__type' => 'Pointer',
                        'className' => 'partners_Tenant',
                        'objectId' => $tenantId,
                    ],
                    'OrganizationId' => [
                        '__type' => 'Pointer',
                        'className' => 'contracts_Organizations',
                        'objectId' => $orgId,
                    ],
                    'TeamIds' => [
                        [
                            '__type' => 'Pointer',
                            'className' => 'contracts_Teams',
                            'objectId' => $teamId,
                        ],
                    ],
                    'TourStatus' => [
                        [
                            'loginTour' => true,
                        ],
                    ],
                ],
            ]);
            $profileData = json_decode($response->getBody()->getContents(), true);
            if (
                ! is_array($profileData) || ! isset($profileData['objectId']) || ! is_string($profileData['objectId'])
            ) {
                throw new \RuntimeException('Failed to create Profile');
            }
            $profileId = $profileData['objectId'];

            // Update Organization with ExtUserId
            $this->client->request('PUT', $this->apiUrl . '/classes/contracts_Organizations/' . $orgId, [
                'headers' => $headers,
                'json' => [
                    'ExtUserId' => [
                        '__type' => 'Pointer',
                        'className' => 'contracts_Users',
                        'objectId' => $profileId,
                    ],
                ],
            ]);
            $io->success('Profile ID: ' . $profileId);

            // 6. Fix user (Tenant link & Admin flags)
            $io->section('6. Updating User metadata');
            $this->client->request('PUT', $this->apiUrl . '/users/' . $userId, [
                'headers' => $headers,
                'json' => [
                    'TenantID' => $tenantId, // Legacy mapping in some versions
                    'IsAdmin' => true,
                    'IsActive' => true,
                ],
            ]);
            $io->success('User metadata updated.');

            // 7. Initial Schema (contracts_Document)
            $io->section('7. Initializing Schema');
            $this->client->request('POST', $this->apiUrl . '/schemas/contracts_Document', [
                'headers' => $headers,
                'json' => [
                    'fields' => [
                        'Name' => [
                            'type' => 'String',
                        ],
                        'URL' => [
                            'type' => 'String',
                        ],
                        'Signers' => [
                            'type' => 'Array',
                        ],
                        'Status' => [
                            'type' => 'String',
                        ],
                        'CreatedBy' => [
                            'type' => 'Pointer',
                            'targetClass' => '_User',
                        ],
                    ],
                ],
                'http_errors' => false,
            ]);
            $io->success('Schema initialized.');

            // 8. Report credentials — never written to disk. Copy these into
            // your secret store (Doppler, Vault, etc.) yourself.
            $io->section('8. Bootstrap Credentials');
            $io->table(['Variable', 'Value'], [
                ['OPENSIGN_USER_ID', $userId],
                ['OPENSIGN_SESSION_TOKEN', $sessionToken],
                ['OPENSIGN_API_USERNAME', (string) $config['admin']['username']],
                ['OPENSIGN_API_PASSWORD', (string) $config['admin']['password']],
            ]);
            $io->warning('These values are shown once and not saved anywhere by this command.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Full setup failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
