<?php

function readOfxData($filename) {
    // Load the OFX data from the specified file
    $ofxData = simplexml_load_file($filename);
    if ($ofxData === false) {
        return false;
    }

    $transactions = [];
    // Extract transactions from OFX data
    foreach ($ofxData->BANKTRANLIST->STMTTRN as $tran) {
        $transactions[] = [
            'date' => (string) $tran->DTPOSTED,
            'name' => (string) $tran->NAME,
            'description' => (string) $tran->MEMO,
            'value' => (float) $tran->TRNAMT,
        ];
    }
    return $transactions;
}

function displayTransactions($transactions) {
    foreach ($transactions as $tran) {
        echo "Date: {$tran['date']}, Name: {$tran['name']}, Description: {$tran['description']}, Value: {$tran['value']}\n";
    }
}

$filename = 'transactions.ofx'; // Specify the OFX file
$transactions = readOfxData($filename);
if ($transactions) {
    displayTransactions($transactions);
} else {
    echo 'Failed to read OFX data.';
}

?>