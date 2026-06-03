<?php
/**
 * UltraDev_MercadoPago – Setup Script v1.0.0
 *
 * Este módulo não requer tabelas adicionais.
 * Todos os dados de pagamento (order_id, payment_id, qr_code, ticket_url, etc.)
 * são armazenados em sales_flat_order_payment.additional_information via
 * $payment->setAdditionalInformation().
 *
 * O script existe para registrar a versão instalada no core_resource
 * e evitar que o OpenMage tente reprocessar o setup a cada requisição.
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

// Nenhuma DDL necessária para esta versão.

$installer->endSetup();
