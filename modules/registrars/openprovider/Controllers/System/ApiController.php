<?php


namespace OpenProvider\WhmcsRegistrar\Controllers\System;


use OpenProvider\API\ApiHelper;
use OpenProvider\WhmcsRegistrar\enums\DatabaseTable;
use OpenProvider\WhmcsRegistrar\helpers\ApiResponse;
use OpenProvider\WhmcsRegistrar\helpers\DB as DBHelper;
use OpenProvider\WhmcsRegistrar\src\OpenProvider;
use WeDevelopCoffee\wPower\Controllers\BaseController;
use WeDevelopCoffee\wPower\Core\Core;
use WHMCS\Database\Capsule;

class ApiController extends BaseController
{
    /**
     * @var OpenProvider
     */
    private $openProvider;
    /**
     * @var ApiHelper
     */
    private $apiHelper;

    /**
     * ApiController constructor.
     */
    public function __construct(Core $core, OpenProvider $openProvider, ApiHelper $apiHelper)
    {
        parent::__construct($core);
        $this->openProvider = $openProvider;
        $this->apiHelper = $apiHelper;
    }

    /**
     * Api function for update contacts tags.
     * Params is whmcs 'userid'.
     *
     * @param array $params [userId]
     */
    public function updateContactsTag($params)
    {
        $userId = (
                isset($params['userid'])
                && !empty($params['userid'])
                && is_int(intval($params['userid']))
            )
            ? intval($params['userid'])
            : false;

        $tag = '';
        if (DBHelper::checkTableExist(DatabaseTable::ClientTags))
            $tag = Capsule::table(DatabaseTable::ClientTags)
                ->where('clientid', $userId)
                ->first();

        $tags = $tag && $tag->tag
            ? [
                [
                    'key' => 'customer',
                    'value' => $tag->tag,
                ]
            ]
            : '';

        $usersContacts = Capsule::table('wHandles')
            ->where([
                ['user_id', '=', $userId],
                ['registrar', '=', 'openprovider']
            ])
            ->select('handle')
            ->get()
            ->map(function ($contact) {
                return $contact->handle;
            });

        $this->_modifyContactsTag($usersContacts, $tags);

        ApiResponse::success();
    }

    /**
     * Update DnssecKeys records by action(delete existed or add new)
     *
     * @param array $params [domainId, action(create|delete), flags, alg, pubKey]
     */
    public function updateDnsSecRecord($params)
    {
        if (!isset($params['domainId']) || empty($params['domainId'])) {
            ApiResponse::error(400, 'domain id is required!');
            return;
        }

        $domainDB = $this->_checkDomainExistInDatabase($params['domainId']);
        if (!$domainDB) {
            ApiResponse::error('Domain not found!');
            return;
        }

        $action = $params['action'];
        $dnssecKey = [
            'flags'    => $params['flags'],
            'alg'      => $params['alg'],
            'protocol' => 3,
            'pubKey'   => $params['pubKey'],
        ];
        $domain = $this->openProvider->domain($domainDB->domain);
        $domainOP = $this->apiHelper->getDomain($domain);

        if (empty($domainOP)) {
            throw new \Exception('Domain not exist in openprovider!');
        }

        // checking for duplicate dnssecKeys
        $dnssecKeys = [];
        $dnssecKeysHashes = [];
        foreach($domainOP['dnssecKeys'] as $dnssec) {
            $dnssecKeysHashes[] = md5($dnssec['flags'] . $dnssec['alg'] . $dnssec['protocol'] . trim($dnssec['pubKey']));
            $dnssecKeys[] = [
                'flags'    => $dnssec['flags'],
                'alg'      => $dnssec['alg'],
                'protocol' => 3,
                'pubKey'   => $dnssec['pubKey'],
            ];
        }

        $modifiedDnsSecKeys = [];
        switch ($action) {
            case 'create':
                $modifiedDnsSecKeys = $this->_createDnssecRecord($dnssecKeys, $dnssecKeysHashes, $dnssecKey);
                break;
            case 'delete':
                $modifiedDnsSecKeys = $this->_deleteDnssecRecord($dnssecKeys, $dnssecKeysHashes, $dnssecKey);
                break;
        }

        // update dnssecKeys with new record,

        $args = [
            'dnssecKeys'      => $modifiedDnsSecKeys,
            'isDnssecEnabled' => count($modifiedDnsSecKeys) > 0,
        ];

        try {
            $this->apiHelper->updateDomain($domainOP['id'], $args);
        } catch (\Exception $e) {
            ApiResponse::error(400, $e->getMessage());
            return;
        }
        ApiResponse::success(['dnssecKeys' => $args['dnssecKeys']]);
    }

    /**
     * Turn on|off dnssecKeys in openprovider
     *
     * @param array $params [domainId, isDnssecEnabled(1|0), ]
     */
    public function updateDnsSecEnabled($params)
    {
        if (!isset($params['domainId']) || empty($params['domainId'])) {
            ApiResponse::error(400, 'domain id is required!');
            return;
        }

        $domainDB = $this->_checkDomainExistInDatabase($params['domainId']);
        if (!$domainDB) {
            ApiResponse::error('Domain not found!');
            return;
        }

        $api = $this->openProvider->api;

        $isDnssecEnabled = $params['isDnssecEnabled'];

        $domain = $this->openProvider->domain($domainDB->domain);
        $args = [
            'isDnssecEnabled' => $isDnssecEnabled,
            'domain'          => $domainArray,
        ];

        try {
            $api->sendRequest('modifyDomainRequest', $args);
        } catch (\Exception $e) {
            ApiResponse::error(400, $e->getMessage());
            return;
        }

        ApiResponse::success();
    }

    /**
     * Return array without duplicates dnssecKeys to save it in openprovider.
     *
     * @param array $dnssecKeys already existed keys
     * @param array $dnssecKeysHashes hashes of already existed keys
     * @param array $dnssecKey new key to add
     * @return mixed
     */
    private function _createDnsSecRecord($dnssecKeys, $dnssecKeysHashes, $dnssecKey)
    {
        if (in_array(md5($dnssecKey['flags'] . $dnssecKey['alg'] . strval(3) . trim($dnssecKey['pubKey'])), $dnssecKeysHashes))
            return $dnssecKeys;

        $dnssecKeys[] = $dnssecKey;
        return $dnssecKeys;
    }

    /**
     * Return array without input dnssecKey.
     *
     * @param array $dnssecKeys already existed keys
     * @param array $dnssecKeysHashes hashes of already existed keys
     * @param array $dnssecKey key to remove from existed keys array
     * @return mixed
     */
    private function _deleteDnsSecRecord($dnssecKeys, $dnssecKeysHashes, $dnssecKey)
    {
        $recordIndex = array_search(md5($dnssecKey['flags'] . $dnssecKey['alg'] . strval(3) . trim($dnssecKey['pubKey'])), $dnssecKeysHashes);
        if ($recordIndex === false)
            return $dnssecKeys;

        unset($dnssecKeys[$recordIndex]);
        return array_values($dnssecKeys);
    }

    /**
     * Update tag of all contacts by contacts handles
     *
     * @param $contactsHandles
     * @param string $tags
     */
    private function _modifyContactsTag($contactsHandles, $tags = '')
    {
        $api = $this->openProvider->api;

        foreach ($contactsHandles as $contactHandle) {
            try {
                $params = [
                    'handle' => $contactHandle,
                    'tags' => $tags,
                ];

                $api->sendRequest('modifyCustomerRequest', $params);
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    /**
     * Return domain by domain's Id from database or false
     *
     * @param int $domainId Domain id from database
     * @return (false|databaseRow)
     */
    private function _checkDomainExistInDatabase($domainId)
    {
        $domain = Capsule::table('tbldomains')
            ->where('id', $domainId)
            ->first();

        if ($domain)
            return $domain;

        return false;
    }

    /**
     * return Domain format ['name' => '*', 'extension' => '.*']
     *
     * @param string $domain
     * @return array
     */
    private function _getDomainNameExtension($domain)
    {
        $domainArray = explode('.', $domain);
        return [
            'extension' => $domainArray[count($domainArray)-1],
            'name' => implode('.', array_slice($domainArray, 0, count($domainArray)-1)),
        ];
    }
}
