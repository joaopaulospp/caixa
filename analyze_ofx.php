<?php

// Function to parse OFX file and summarize transactions
function summarizeOFX($filePath) {
    // Load the XML file
    if (!file_exists($filePath)) {
        die('Error: File not found.');
    }

    $xml = simplexml_load_file($filePath);
    if ($xml === false) {
        die('Error: Unable to parse XML.');
    }

    // Initialize summary
    $summary = [];

    // Iterate through transactions (assuming they are under <STMTTRN>)
    foreach ($xml-> BANKMSGSRSV1-> STMTTRN as $transaction) {
        $date = (string) $transaction->DTPOSTED;
        $amount = (float) $transaction->TRNAMT;
        $type = (string) $transaction->TRNTYPE;
        $description = (string) $transaction->MEMO;

        $summary[] = [
            'date' => $date,
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
        ];
    }

    return $summary;
}

// Example usage
$filePath = 'path/to/your/ofx/file.ofx'; // Replace with actual path
$transactions = summarizeOFX($filePath);

// Output summary
foreach ($transactions as $transaction) {
    echo "Date: {$transaction['date']}, Amount: {$transaction['amount']}, Type: {$transaction['type']}, Description: {$transaction['description']}\n";
}
?>