<?php
class BkashController extends AppController {
    public function preAction() {
        parent::preAction();
        $this->requireLogin();
        Language::loadLang('bkash', null, PLUGINDIR . 'bkash' . DS . 'language' . DS);
    }

    public function index() {
        $this->redirect($this->base_uri . 'plugin/bkash/client_main/');
    }

    public function process() {
        $this->uses(['GatewayManager']);
        $gateway = $this->GatewayManager->getByClass('Bkash', $this->company_id);

        if (!$gateway) {
            $this->setMessage('error', Language::_('Bkash.!error.gateway_not_installed', true));
            $this->redirect($this->base_uri);
        }

        $amount = $this->post['amount'] ?? 0;
        $contact_info = $this->post['contact_info'] ?? [];
        $invoice_amounts = $this->post['invoices'] ?? [];

        $gateway_obj = $this->GatewayManager->initGateway($gateway->class, $gateway->company_id);
        $form = $gateway_obj->buildProcess($contact_info, $amount, $invoice_amounts);

        if ($form === false) {
            $this->setMessage('error', $gateway_obj->errors()[0] ?? Language::_('Bkash.!error.payment_failed', true));
            $this->redirect($this->base_uri);
        }

        $this->set('form', $form);
        $this->view->setView(null, 'Bkash.client.process');
    }

    public function callback() {
        $this->uses(['GatewayManager']);
        $gateway = $this->GatewayManager->getByClass('Bkash', $this->company_id);

        if (!$gateway) {
            $this->log('Bkash Callback', 'Gateway not installed.', 'error');
            return;
        }

        $gateway_obj = $this->GatewayManager->initGateway($gateway->class, $gateway->company_id);
        $response = $gateway_obj->validate($this->get, $this->post);

        if ($response['status'] === 'approved') {
            $this->GatewayManager->processResponse(
                $response['transaction_id'],
                $response['amount'],
                'approved',
                $this->post['paymentID'],
                null,
                $gateway->id
            );
            $this->log('Bkash Callback', 'Payment approved: ' . $response['transaction_id'], 'success');
        } else {
            $this->log('Bkash Callback', 'Payment declined.', 'error');
        }
    }

    public function success() {
        $this->uses(['GatewayManager']);
        $gateway = $this->GatewayManager->getByClass('Bkash', $this->company_id);

        if (!$gateway) {
            $this->setMessage('error', Language::_('Bkash.!error.gateway_not_installed', true));
            $this->redirect($this->base_uri);
        }

        $gateway_obj = $this->GatewayManager->initGateway($gateway->class, $gateway->company_id);
        $response = $gateway_obj->success($this->get, $this->post);

        if ($response['status'] === 'approved') {
            $this->setMessage('success', Language::_('Bkash.!success.payment_processed', true));
        } else {
            $this->setMessage('error', Language::_('Bkash.!error.payment_failed', true));
        }

        $this->redirect($this->base_uri . 'pay/');
    }
}