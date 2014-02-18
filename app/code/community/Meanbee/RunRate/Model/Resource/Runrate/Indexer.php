<?php
class Meanbee_RunRate_Model_Resource_Runrate_Indexer extends Mage_Index_Model_Resource_Abstract
{
    const MYSQL_DATETIME_FORMAT = 'Y-m-d H:i:s';

    protected function _construct() {
        $this->_init('meanbee_runrate/runrate', 'product_id');
    }

    public function reindexAll()
    {
        $this->beginTransaction();

        try {
            $weeks_for_average = Mage::helper('meanbee_runrate/config')->getWeeksForAverage();
            $leadtime_attribute_code = Mage::helper('meanbee_runrate/config')->getLeadTimeAttributeCode();

            $start_date = time() - ($weeks_for_average * 7 * 24 * 60);
            $end_date = time();

            $product_select = Mage::getModel('catalog/product')->getCollection()
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('sku')
                ->joinAttribute('name', 'catalog_product/name', 'entity_id', null, 'left')
                ->joinAttribute('lead_time', 'catalog_product/' . $leadtime_attribute_code, 'entity_id', null, 'left')
                ->getSelect();

            $product_select
                ->join(array('i' => $this->getTable('cataloginventory/stock_status')), 'i.product_id = e.entity_id', '')
                ->joinLeft(array('oi' => $this->getTable('sales/order_item')), 'oi.product_id = e.entity_id', '')
                ->join(array('o' => $this->getTable('sales/order')), 'o.entity_id = oi.order_id', '')
                ->where(new Zend_Db_Expr(sprintf(
                    "o.created_at BETWEEN '%s' AND '%s'",
                    date(self::MYSQL_DATETIME_FORMAT, $start_date),
                    date(self::MYSQL_DATETIME_FORMAT, $end_date)
                )))
                ->group('e.entity_id');

            $average_run_sql_fragment = sprintf("(SUM(oi.qty_ordered) / %d)", $weeks_for_average);
            $weeks_remaining_sql_fragment = sprintf("(i.qty / %s) ", $average_run_sql_fragment);

            $product_select->reset(Zend_Db_Select::COLUMNS);
            $product_select->columns(array(
                'e.entity_id AS product_id',
                new Zend_Db_Expr(sprintf(
                    "IF(%s < at_lead_time.value, 'warning', IF(%s < at_lead_time.value * 1.2,'order_soon','safe')) AS status",
                    $weeks_remaining_sql_fragment,
                    $weeks_remaining_sql_fragment
                )),
                'e.sku AS sku',
                'at_name.value AS name',
                'i.qty AS current_stock',
                'at_lead_time.value AS lead_time',
                new Zend_Db_Expr("$average_run_sql_fragment AS `average_run`"),
                new Zend_Db_Expr("$weeks_remaining_sql_fragment AS `weeks_remaining_at_average_run`")
            ));

            $this->insertFromSelect($product_select, $this->getMainTable(), array(
                'product_id',
                'status',
                'sku',
                'name',
                'current_stock',
                'lead_time',
                'average_run',
                'weeks_remaining_at_average_run'
            ));

            $this->commit();
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }

        return $this;
    }
}
