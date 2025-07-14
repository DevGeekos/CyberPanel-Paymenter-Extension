<?php

namespace Paymenter\Extensions\Servers\CyberPanel;

use App\Classes\Extension\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CyberPanel extends Server
{
    private function apiRequest($endpoint, $data = [])
    {
        $config = $this->config;
        $url = rtrim($config['host'], '/') . '/' . ltrim($endpoint, '/');

        $postData = [
            'adminUser' => $config['username'],
            'adminPass' => $config['password'],
        ];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $postData[$key] = trim($value);
            } else {
                $postData[$key] = $value;
            }
        }

        Log::info('CyberPanel API Request', [
            'endpoint' => $endpoint,
            'url' => $url,
            'data' => $postData
        ]);

        try {
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $postData);

            Log::info('CyberPanel API Response', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                return [
                    'error' => 'API request failed with status: ' . $response->status(),
                    'response_body' => $response->body(),
                    'url' => $url
                ];
            }
        } catch (\Exception $e) {
            return ['error' => 'API request failed: ' . $e->getMessage()];
        }
    }

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'URL du serveur CyberPanel',
                'description' => 'URL complète avec le port (ex: https://cyberpanel.exemple.com:8090)',
                'type' => 'text',
                'friendlyName' => 'Host',
                'required' => true,
            ],
            [
                'name' => 'username',
                'label' => 'Nom d\'utilisateur Admin',
                'description' => 'Nom d\'utilisateur administrateur pour l\'API CyberPanel',
                'type' => 'text',
                'friendlyName' => 'Username',
                'required' => true,
            ],
            [
                'name' => 'password',
                'label' => 'Mot de passe Admin',
                'description' => 'Mot de passe administrateur pour l\'API CyberPanel',
                'type' => 'password',
                'friendlyName' => 'Password',
                'required' => true,
            ],
        ];
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'packageName',
                'label' => 'Nom du Package',
                'description' => 'Nom du package CyberPanel à utiliser',
                'type' => 'text',
                'friendlyName' => 'Package Name',
                'required' => true,
                'default' => 'Default',
            ],
            [
                'name' => 'default_password',
                'label' => 'Mot de passe par défaut',
                'description' => 'Mot de passe par défaut pour les nouveaux utilisateurs',
                'type' => 'text',
                'friendlyName' => 'Default Password',
                'required' => true,
                'default' => 'TempPass123!',
            ],
            [
                'name' => 'domain_name',
                'label' => 'Nom de domaine',
                'description' => 'Nom de domaine souhaité (ex: monsite.com)',
                'type' => 'text',
                'friendlyName' => 'Domain Name',
                'required' => true,
                'configurable' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        $response = $this->apiRequest('api/verifyConn');
        
        Log::info('CyberPanel Test Connection', $response);
        
        if (isset($response['verifyConn']) && $response['verifyConn'] == 1) {
            return true;
        }
        
        return 'Échec de la connexion API - Réponse: ' . json_encode($response);
    }

    public function createServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $orderUser = $service->user;

        $email = $orderUser->email;
        $username = $email;
        $domainName = trim($settings['domain_name']);
        
        if (empty($domainName)) {
            Log::error('CyberPanel Domain Name is Required', ['settings' => $settings]);
            return false;
        }
        
        $password = $settings['default_password'] ?? 'TempPass123!';
        $packageName = trim(preg_replace('/\s+/', '', $settings['packageName'] ?? 'Default'));

        Log::info('CyberPanel Creating Server', [
            'username' => $username,
            'domain' => $domainName,
            'email' => $email,
            'package' => $packageName
        ]);

        // Check if user already exists
        $userInfoResponse = $this->apiRequest('api/getUserInfo', [
            'userName' => $username
        ]);

        Log::info('CyberPanel Check User Exists', [
            'username' => $username,
            'response' => $userInfoResponse
        ]);

        $userExists = $this->checkUserExists($userInfoResponse);

        if ($userExists) {
            Log::info('CyberPanel User Already Exists, Skipping Creation', ['username' => $username]);
        } else {
            Log::info('CyberPanel User Does Not Exist, Creating...', ['username' => $username]);
            
            $userData = [
                'firstName' => !empty($orderUser->first_name) ? trim($orderUser->first_name) : 'User',
                'lastName' => !empty($orderUser->last_name) ? trim($orderUser->last_name) : 'Account',
                'userName' => $username,
                'email' => $email,
                'password' => $password,
                'packageName' => $packageName,
                'selectedACL' => 'user',
                'websitesLimit' => 0,
            ];

            if (empty($userData['firstName']) || empty($userData['lastName'])) {
                Log::error('CyberPanel Missing Required Fields', [
                    'firstName' => $userData['firstName'],
                    'lastName' => $userData['lastName'],
                    'original_first_name' => $orderUser->first_name,
                    'original_last_name' => $orderUser->last_name
                ]);
                return false;
            }

            $userResponse = $this->apiRequest('api/submitUserCreation', $userData);
            
            if (!$this->isUserCreationSuccessful($userResponse)) {
                $error = $this->getErrorMessage($userResponse, 'Failed to create user');
                Log::error('CyberPanel User Creation Failed', ['error' => $error]);
                return false;
            }

            Log::info('CyberPanel User Created Successfully', ['username' => $username]);
        }

        // Wait before creating website
        Log::info('CyberPanel Waiting before website creation...');
        sleep(3);

        // Check if website already exists
        $websiteInfoResponse = $this->apiRequest('api/getWebsiteDetails', [
            'domainName' => $domainName
        ]);

        Log::info('CyberPanel Check Website Exists', [
            'domain' => $domainName,
            'response' => $websiteInfoResponse
        ]);

        if (!$this->isSuccessResponse($websiteInfoResponse, 'websiteStatus')) {
            Log::info('CyberPanel Website Does Not Exist, Creating...', ['domain' => $domainName]);
            
            $websiteData = [
                'domainName' => $domainName,
                'ownerEmail' => $email,
                'packageName' => $packageName,
                'websiteOwner' => $username,
                'ownerPassword' => $password,
            ];

            $websiteResponse = $this->apiRequest('api/createWebsite', $websiteData);
            
            if (!$this->isSuccessResponse($websiteResponse, 'createWebSiteStatus')) {
                $error = $this->getErrorMessage($websiteResponse, 'Failed to create website');
                Log::warning('CyberPanel Website Creation Failed', ['error' => $error]);
            } else {
                Log::info('CyberPanel Website Created Successfully', ['domain' => $domainName]);
            }
        } else {
            Log::info('CyberPanel Website Already Exists', ['domain' => $domainName]);
        }

        Log::info('CyberPanel Server Creation Completed', [
            'username' => $username,
            'domain' => $domainName,
        ]);

        return true;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $domainName = trim($settings['domain_name']);
        
        if (empty($domainName)) {
            Log::error('CyberPanel Domain Name is Required for Suspension', ['settings' => $settings]);
            return false;
        }

        Log::info('CyberPanel Suspending Website', ['domain' => $domainName]);

        $response = $this->apiRequest('api/submitWebsiteStatus', [
            'websiteName' => $domainName,
            'state' => 'Suspend',
        ]);

        if (!$this->isSuccessResponse($response, 'websiteStatus')) {
            $error = $this->getErrorMessage($response, 'Failed to suspend website');
            Log::error('CyberPanel Website Suspension Failed', ['error' => $error, 'domain' => $domainName]);
            return false;
        }

        Log::info('CyberPanel Website Suspended Successfully', ['domain' => $domainName]);
        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $domainName = trim($settings['domain_name']);
        
        if (empty($domainName)) {
            Log::error('CyberPanel Domain Name is Required for Unsuspension', ['settings' => $settings]);
            return false;
        }

        Log::info('CyberPanel Unsuspending Website', ['domain' => $domainName]);

        $response = $this->apiRequest('api/submitWebsiteStatus', [
            'websiteName' => $domainName,
            'state' => 'Activate',
        ]);

        if (!$this->isSuccessResponse($response, 'websiteStatus')) {
            $error = $this->getErrorMessage($response, 'Failed to unsuspend website');
            Log::error('CyberPanel Website Unsuspension Failed', ['error' => $error, 'domain' => $domainName]);
            return false;
        }

        Log::info('CyberPanel Website Unsuspended Successfully', ['domain' => $domainName]);
        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);
        $domainName = trim($settings['domain_name']);
        
        if (empty($domainName)) {
            Log::error('CyberPanel Domain Name is Required for Termination', ['settings' => $settings]);
            return false;
        }

        Log::info('CyberPanel Terminating Website', ['domain' => $domainName]);

        $response = $this->apiRequest('api/deleteWebsite', [
            'domainName' => $domainName,
        ]);

        if (!$this->isSuccessResponse($response, 'deleteWebSiteStatus')) {
            $error = $this->getErrorMessage($response, 'Failed to terminate website');
            Log::error('CyberPanel Website Termination Failed', ['error' => $error, 'domain' => $domainName]);
            return false;
        }

        Log::info('CyberPanel Website Terminated Successfully', ['domain' => $domainName]);
        return true;
    }

    /**
     * Check if user exists based on API response
     */
    private function checkUserExists($response)
    {
        if (isset($response['error'])) {
            return false;
        }

        if (isset($response['status']) && $response['status'] == 1) {
            return true;
        }

        if (isset($response['status']) && $response['status'] == 0) {
            $errorMessage = $response['error_message'] ?? '';
            if ($errorMessage === "'username'") {
                return false;
            }
        }

        return false;
    }

    /**
     * Check if user creation was successful, handling duplicate entries
     */
    private function isUserCreationSuccessful($response)
    {
        if (isset($response['error'])) {
            return false;
        }

        if (isset($response['status']) && $response['status'] == 1) {
            return true;
        }

        if (isset($response['status']) && $response['status'] == 0) {
            $errorMessage = $response['error_message'] ?? '';
            
            if (strpos($errorMessage, 'Duplicate entry') !== false && strpos($errorMessage, 'userName') !== false) {
                Log::info('CyberPanel User Already Exists (Duplicate Key)', ['error' => $errorMessage]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if API response indicates success
     */
    private function isSuccessResponse($response, $statusKey)
    {
        if (isset($response['error'])) {
            return false;
        }

        if (isset($response[$statusKey])) {
            return $response[$statusKey] == 1;
        }

        if (isset($response['status'])) {
            return $response['status'] == 1;
        }

        return !isset($response['error']);
    }

    /**
     * Extract error message from API response
     */
    private function getErrorMessage($response, $defaultMessage)
    {
        $possibleErrorKeys = ['error', 'error_message', 'errorMessage', 'message'];
        
        foreach ($possibleErrorKeys as $key) {
            if (isset($response[$key]) && !empty($response[$key])) {
                return $response[$key];
            }
        }
        
        return $defaultMessage . ' - Response: ' . json_encode($response);
    }

    /**
     * Get user information from CyberPanel
     */
    public function getUserInfo($username)
    {
        $response = $this->apiRequest('api/getUserInfo', [
            'userName' => $username
        ]);
        
        if (isset($response['error'])) {
            Log::error('CyberPanel Get User Info Failed', ['error' => $response['error']]);
            return false;
        }
        
        return $response;
    }

    /**
     * Fetch users (not supported by CyberPanel API)
     */
    public function fetchUsers()
    {
        Log::warning('CyberPanel fetchUsers endpoint does not exist, use getUserInfo instead');
        return false;
    }

    /**
     * Get website details from CyberPanel
     */
    public function getWebsiteDetails($domainName)
    {
        $response = $this->apiRequest('api/fetchWebsiteDetails', [
            'domainName' => $domainName,
        ]);
        
        if (isset($response['error'])) {
            Log::error('CyberPanel Fetch Website Details Failed', ['error' => $response['error']]);
            return false;
        }
        
        return $response;
    }

    /**
     * Get usage statistics for a user
     */
    public function getUsageStats($username)
    {
        $response = $this->apiRequest('api/fetchUsageStats', [
            'userName' => $username,
        ]);
        
        if (isset($response['error'])) {
            Log::error('CyberPanel Fetch Usage Stats Failed', ['error' => $response['error']]);
            return false;
        }
        
        return $response;
    }
}
