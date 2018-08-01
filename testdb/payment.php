<?php

$db['payment'] = array(
    'columns' => array(
        'payment_id' => array(
            'type' => 'varchar(20)',
            'required' => true,
            'default' => '1',
            'pkey' => true,
            'comment' => '支付单号1',
        ),
        'account' => array(
            'type' => 'varchar(50)',
            'label' => '支付账户',
            'default' => '',
            'width' => 110,
            'editable' => false,
            'in_list' => true,
        ),
        'bank' => array(
            'type' => 'varchar(20)',
            'comment' => '支付银行',
        ),
        'status' =>
            array(
                'type' =>
                    array(
                        0 => '待支付',
                        1 => '支付成功',
                        2 => '支付失败',
                    ),
                'default' => '0',
                'comment' => '支付',
            ),
    ),

    'index' => array(
        'ind_payment_id' => array(
            'columns' => array(
                0 => 'payment_id',
            ),
        ),
    ),
    'engine' => 'innodb',
    'comment' => '支付单表',
);
