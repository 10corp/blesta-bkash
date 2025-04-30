<?php
/**
 * bKash Non-merchant Gateway for Blesta
 *
 * @package   Blesta
 * @author    Mahmudul Hasan
 * @link      https://www.10corp.com
 */

use Blesta\Core\Util\Input\Fields\InputFields;

class Bkash extends NonmerchantGateway
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->loadLanguage();
        Loader::loadComponents($this, ['Input']);
        Loader::loadModels($this, ['Clients']);
    }

    /**
     * Load language file
     */
    private function loadLanguage()
    {
        Language::loadLang('bkash', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Returns the name of the gateway
     *
     * @return string
     */
    public function getName()
    {
        return Language::_('Bkash.name', true);
    }

    /**
     * Returns the logo for the gateway
     *
     * @return string
     */
    public function getLogo()
    {
        return '';
    }

    /**
     * Returns all currencies supported
     *
     * @return array
     */
    public function getCurrencies()
    {
        return ['BDT'];
    }

    /**
     * Set the currency code
     *
     * @param string $currency
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Returns all fields used to configure the gateway
     *
     * @param array $meta
     * @return array
     */
    public function getSettings(array $meta = null)
    {
        $fields = new InputFields();

        // Username
        $username = $fields->label(Language::_('Bkash.username', true), 'bkash_username');
        $username->attach(
            $fields->fieldText(
                'meta[username]',
                isset($meta['username']) ? $meta['username'] : null,
                ['id' => 'bkash_username']
            )
        );
        $fields->setField($username);

        // Password
        $password = $fields->label(Language::_('Bkash.password', true), 'bkash_password');
        $password->attach(
            $fields->fieldPassword(
                'meta[password]',
                ['id' => 'bkash_password', 'value' => isset($meta['password']) ? $meta['password'] : null]
            )
        );
        $fields->setField($password);

        // App Key
        $app_key = $fields->label(Language::_('Bkash.app_key', true), 'bkash_app_key');
        $app_key->attach(
            $fields->fieldText(
                'meta[app_key]',
                isset($meta['app_key']) ? $meta['app_key'] : null,
                ['id' => 'bkash_app_key']
            )
        );
        $fields->setField($app_key);

        // App Secret
        $app_secret = $fields->label(Language::_('Bkash.app_secret', true), 'bkash_app_secret');
        $app_secret->attach(
            $fields->fieldText(
                'meta[app_secret]',
                isset($meta['app_secret']) ? $meta['app_secret'] : null,
                ['id' => 'bkash_app_secret']
            )
        );
        $fields->setField($app_secret);

        // Sandbox Mode
        $sandbox = $fields->label(Language::_('Bkash.sandbox', true), 'bkash_sandbox');
        $sandbox->attach(
            $fields->fieldCheckbox(
                'meta[sandbox]',
                'true',
                (isset($meta['sandbox']) && $meta['sandbox'] == 'true'),
                ['id' => 'bkash_sandbox']
            )
        );
        $fields->setField($sandbox);

        return $fields->getFields();
    }

    /**
     * Edit settings for the gateway
     *
     * @param array $meta
     * @return array
     */
    public function editSettings(array $meta)
    {
        $fields = new InputFields();

        // Username
        $username = $fields->label(Language::_('Bkash.username', true), 'bkash_username');
        $username->attach(
            $fields->fieldText(
                'meta[username]',
                isset($meta['username']) ? $meta['username'] : null,
                ['id' => 'bkash_username']
            )
        );
        $fields->setField($username);

        // Password
        $password = $fields->label(Language::_('Bkash.password', true), 'bkash_password');
        $password->attach(
            $fields->fieldPassword(
                'meta[password]',
                ['id' => 'bkash_password', 'value' => isset($meta['password']) ? $meta['password'] : null]
            )
        );
        $fields->setField($password);

        // App Key
        $app_key = $fields->label(Language::_('Bkash.app_key', true), 'bkash_app_key');
        $app_key->attach(
            $fields->fieldText(
                'meta[app_key]',
                isset($meta['app_key']) ? $meta['app_key'] : null,
                ['id' => 'bkash_app_key']
            )
        );
        $fields->setField($app_key);

        // App Secret
        $app_secret = $fields->label(Language::_('Bkash.app_secret', true), 'bkash_app_secret');
        $app_secret->attach(
            $fields->fieldText(
                'meta[app_secret]',
                isset($meta['app_secret']) ? $meta['app_secret'] : null,
                ['id' => 'bkash_app_secret']
            )
        );
        $fields->setField($app_secret);

        // Sandbox Mode
        $sandbox = $fields->label(Language::_('Bkash.sandbox', true), 'bkash_sandbox');
        $sandbox->attach(
            $fields->fieldCheckbox(
                'meta[sandbox]',
                'true',
                (isset($meta['sandbox']) && $meta['sandbox'] == 'true'),
                ['id' => 'bkash_sandbox']
            )
        );
        $fields->setField($sandbox);

        return $fields->getFields();
    }

    /**
     * Set meta data for the gateway
     *
     * @param ?array $meta
     */
    public function setMeta(?array $meta = null)
    {
        if ($meta !== null) {
            if ($errors = $this->validateSettings($meta)) {
                $this->Input->setErrors($errors);
                return;
            }
        }
        $this->meta = $meta ?? [];
    }

    /**
     * Returns fields that should be encrypted
     *
     * @return array
     */
    public function encryptableFields()
    {
        return ['password', 'app_key', 'app_secret'];
    }

    /**
     * Validates the configuration fields
     *
     * @param array $meta
     * @return array
     */
    public function validateSettings(array $meta)
    {
        $rules = [
            'username' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.username.empty', true)
                ]
            ],
            'password' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.password.empty', true)
                ]
            ],
            'app_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.app_key.empty', true)
                ]
            ],
            'app_secret' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.app_secret.empty', true)
                ]
            ],
            'sandbox' => [
                'valid' => [
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('Bkash.!error.sandbox.valid', true)
                ]
            ]
        ];

        $this->Input->setRules($rules);
        $errors = $this->Input->validates($meta);

        if (!empty($errors)) {
            return $errors;
        }

        return [];
    }

    /**
     * Validates GET/POST data (required by NonmerchantGateway)
     *
     * @param array $get
     * @param array $post
     * @return array
     */
    public function validate(array $get, array $post)
    {
        return [];
    }

    /**
     * Builds and returns the payment form
     *
     * @param array $contact_info
     * @param mixed $amount
     * @param ?array $invoice_amounts
     * @param ?array $options
     * @return mixed
     */
    public function buildProcess(array $contact_info, $amount, ?array $invoice_amounts = null, ?array $options = null)
    {
        if (!isset($this->meta['username']) || !isset($this->meta['password']) ||
            !isset($this->meta['app_key']) || !isset($this->meta['app_secret'])) {
            $this->Input->setErrors(['configuration' => ['missing' => Language::_('Bkash.!error.configuration.missing', true)]]);
            return false;
        }

        if (!isset($options['client_id'])) {
            $this->Input->setErrors(['client' => ['missing' => Language::_('Bkash.!error.client.missing', true)]]);
            return false;
        }

        $token = $this->getToken();
        if (!$token) {
            $this->Input->setErrors(['token' => ['failed' => Language::_('Bkash.!error.token.failed', true)]]);
            return false;
        }

        $client = $this->Clients->get($options['client_id']);
        if (!$client) {
            $this->Input->setErrors(['client' => ['invalid' => Language::_('Bkash.!error.client.invalid', true)]]);
            return false;
        }

        $amount = number_format($amount, 2, '.', '');
        $base_url = $this->meta['sandbox'] == 'true' ? 'https://checkout.sandbox.bka.sh/v1.2.0-beta' : 'https://checkout.pay.bka.sh/v1.2.0-beta';
        $invoice_id = isset($options['invoice_id']) ? $options['invoice_id'] : 'INV' . time();

        $data = [
            'amount' => $amount,
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $invoice_id,
            'payerReference' => $client->id,
        ];

        try {
            $instance = new \GuzzleHttp\Client([
                'base_uri' => $base_url,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'X-APP-Key' => $this->meta['app_key'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);

            $response = $instance->post('/checkout/payment/create', [
                'json' => $data
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->Input->setErrors(['api' => ['status' => Language::_('Bkash.!error.api.status', true)]]);
                return false;
            }

            $result = json_decode($response->getBody(), true);

            if (isset($result['bkashURL'])) {
                $this->log($base_url . '/checkout/payment/create', json_encode($data), 'input', true);
                $this->log($base_url . '/checkout/payment/create', $response->getBody(), 'output', true);
                return $this->buildForm($result['bkashURL']);
            } else {
                $this->Input->setErrors(['payment' => ['failed' => Language::_('Bkash.!error.payment.failed', true)]]);
                return false;
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->Input->setErrors(['request' => ['failed' => Language::_('Bkash.!error.request.failed', true)]]);
            $this->log($base_url . '/checkout/payment/create', $e->getMessage(), 'error', false);
            return false;
        } catch (\Exception $e) {
            $this->Input->setErrors(['request' => ['failed' => Language::_('Bkash.!error.request.failed', true)]]);
            $this->log($base_url . '/checkout/payment/create', $e->getMessage(), 'error', false);
            return false;
        }
    }

    /**
     * Builds the redirect form
     *
     * @param string $bkash_url
     * @return string
     */
    private function buildForm($bkash_url)
    {
        return '<form id="bkash_form" action="' . htmlspecialchars($bkash_url) . '" method="GET">
                    <input type="submit" value="' . Language::_('Bkash.redirect_button', true) . '" />
                </form>
                <script type="text/javascript">
                    document.getElementById("bkash_form").submit();
                </script>
                <noscript>
                    <p>' . Language::_('Bkash.redirect_js_required', true) . '</p>
                </noscript>';
    }

    /**
     * Handles the return from the processor
     *
     * @param array $get
     * @param array $post
     * @return array
     */
    public function success(array $get, array $post)
    {
        if (!isset($get['paymentID']) || !isset($get['status']) || $get['status'] !== 'success') {
            $this->Input->setErrors(['callback' => ['invalid' => Language::_('Bkash.!error.callback.invalid', true)]]);
            return [];
        }

        $token = $this->getToken();
        if (!$token) {
            $this->Input->setErrors(['token' => ['failed' => Language::_('Bkash.!error.token.failed', true)]]);
            return [];
        }

        $base_url = $this->meta['sandbox'] == 'true' ? 'https://checkout.sandbox.bka.sh/v1.2.0-beta' : 'https://checkout.pay.bka.sh/v1.2.0-beta';

        try {
            $instance = new \GuzzleHttp\Client([
                'base_uri' => $base_url,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'X-APP-Key' => $this->meta['app_key'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);

            $response = $instance->get('/checkout/payment/query/' . $get['paymentID']);
            if ($response->getStatusCode() !== 200) {
                $this->Input->setErrors(['api' => ['status' => Language::_('Bkash.!error.api.status', true)]]);
                return [];
            }

            $result = json_decode($response->getBody(), true);

            $this->log($base_url . '/checkout/payment/query/' . $get['paymentID'], $response->getBody(), 'output', true);

            if (isset($result['transactionStatus']) && $result['transactionStatus'] === 'Completed') {
                return [
                    'status' => 'approved',
                    'reference_id' => isset($result['merchantInvoiceNumber']) ? $result['merchantInvoiceNumber'] : null,
                    'transaction_id' => $get['paymentID'],
                    'amount' => isset($result['amount']) ? $result['amount'] : null,
                    'currency' => isset($result['currency']) ? $result['currency'] : 'BDT'
                ];
            } else {
                $this->Input->setErrors(['transaction' => ['failed' => Language::_('Bkash.!error.transaction.failed', true)]]);
                return [];
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->Input->setErrors(['request' => ['failed' => Language::_('Bkash.!error.request.failed', true)]]);
            $this->log($base_url . '/checkout/payment/query/' . $get['paymentID'], $e->getMessage(), 'error', false);
            return [];
        } catch (\Exception $e) {
            $this->Input->setErrors(['request' => ['failed' => Language::_('Bkash.!error.request.failed', true)]]);
            $this->log($base_url . '/checkout/payment/query/' . $get['paymentID'], $e->getMessage(), 'error', false);
            return [];
        }
    }

    /**
     * Fetches a token from bKash
     *
     * @return mixed
     */
    private function getToken()
    {
        $base_url = $this->meta['sandbox'] == 'true' ? 'https://checkout.sandbox.bka.sh/v1.2.0-beta' : 'https://checkout.pay.bka.sh/v1.2.0-beta';

        try {
            $instance = new \GuzzleHttp\Client([
                'base_uri' => $base_url,
                'headers' => [
                    'username' => $this->meta['username'],
                    'password' => $this->meta['password'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);

            $response = $instance->post('/checkout/token/grant', [
                'json' => [
                    'app_key' => $this->meta['app_key'],
                    'app_secret' => $this->meta['app_secret']
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->Input->setErrors(['api' => ['status' => Language::_('Bkash.!error.api.status', true)]]);
                return false;
            }

            $result = json_decode($response->getBody(), true);

            $this->log($base_url . '/checkout/token/grant', json_encode(['app_key' => substr($this->meta['app_key'], 0, 4) . '****']), 'input', true);
            $this->log($base_url . '/checkout/token/grant', $response->getBody(), 'output', true);

            return isset($result['id_token']) ? $result['id_token'] : false;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $this->Input->setErrors(['request' => ['failed' => Language::_('Bkash.!error.request.failed', true)]]);
            $this->log($base_url . '/checkout/token/grant', $e->getMessage(), 'error', false);
            return false;
        } catch (\Exception $e) {
            $this->Input->setErrors(['request' => ['failed' => Language::_('Bkash.!error.request.failed', true)]]);
            $this->log($base_url . '/checkout/token/grant', $e->getMessage(), 'error', false);
            return false;
        }
    }
}