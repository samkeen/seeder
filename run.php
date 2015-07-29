<?php

require 'vendor/autoload.php';
$executionStartTime = microtime(true);
$executionUuid = uniqid() . bin2hex(openssl_random_pseudo_bytes(2));

$executionMetrics = [
    "executionUuid" => $executionUuid,
    "startTime" => $executionStartTime,
    "amiBuildQueueName" => "",
    "jobBuildTemplate" => "",
    "jobBuildTemplateSha" => "",
    "jobReceived" => false,
    "createdAmiId" => "",
    "createdAmiRegion" => "",
    "endedInError" => false,
    "endTime" => ""
];

$logger = new Katzgrau\KLogger\Logger(
    __DIR__ . '/logs',
    Psr\Log\LogLevel::DEBUG,
    [
        'extension' => 'log',
        'filename' => 'app',
        'logFormat' => "[{date}] [{$executionUuid}] [{level}] {message}"
    ]
);

$configPath = __DIR__ . "/config/config.json";

try {
    $configFilePath = __DIR__ . "/config/config.yml";
    $config = new \Io\Samk\AmiBuilder\Utils\Config($configFilePath);
} catch (\Exception $e) {
    $logger->error(
        "There was a problem loading the config file are path: '{$configFilePath}'. Error: '{$e->getMessage()}'");
    exit("Problem Loading Config File.  Error: '{$e->getMessage()}'");
}

$awsConfig = $config->get('awsConfig');
$sqsConfig = $config->get('sqsConfig');
$sqsQueueAttributes = $sqsConfig['queueAttributes'];
/**
 * Init AWS SDK
 */
$aws = \Aws\Common\Aws::factory($awsConfig);

/**
 * Read SQS
 */
/** @var \Aws\Sqs\SqsClient $sqsClient */
$sqsClient = $aws->get('sqs');
$queueUrl = '';
$executionMetrics['amiBuildQueueName'] = $sqsConfig['amiBuildRequestQueueName'];
try {
    // create Queue if not exists
    $createQueueResponse = $sqsClient->createQueue([
        'QueueName' => $sqsConfig['amiBuildRequestQueueName'],
        'Attributes' => $sqsQueueAttributes
    ]);
    $queueUrl = $createQueueResponse->get('QueueUrl');
//    deleteQueue($sqsClient, $sqsConfig['amiBuildRequestQueueName']);

} catch (\Aws\Sqs\Exception\SqsException $e) {
    exit ("ERROR: {$e->getMessage()}");
} catch (Exception $e) {
    exit ("ERROR: {$e->getMessage()}" . "\n");
}

/**
 * if Work Found
 */
$work = $sqsClient->receiveMessage([
    "QueueUrl" => $queueUrl,
    "MaxNumberOfMessages" => 1,
    "MessageAttributeNames" => []
]);

$message = $work->get("Messages")[0];

/**
 * extract Template name and SHA
 */
$messagePayload = json_decode($message['Body'], true);
if (json_last_error()) {
    exit("There was a JSON parse error on the work item Body: " . json_last_error_msg());
}

if (!$messagePayload) {
    $logger->info('No work found');
    exit("No Work found");
}
$messagePayload = array_change_key_case($messagePayload, CASE_LOWER);
$template = ltrim($messagePayload['templatename'], ' /');
$templateSha = $messagePayload['sha'];
$executionMetrics['jobBuildTemplate'] = $template;
$executionMetrics['jobBuildTemplateSha'] = $templateSha;
$logger->info("Found Work, request for template '{$template}' @ SHA '{$templateSha}'");
$executionMetrics['jobReceived'] = true;

$cli = new \Io\Samk\AmiBuilder\Utils\Cli($logger);

/**
 * update template checkout
 */
$templatesConfig = $config->get('templates');
$templatesRepo = $templatesConfig['templatesRepo'];
$templatesCheckoutPath = $templatesConfig['checkoutPath'];

if (!file_exists($templatesCheckoutPath)) {
    list($output, $returnCode) = $cli->execute("git clone {$templatesRepo} {$templatesCheckoutPath}");
}
$logger->info("checking out local packer templates repo '{$templatesCheckoutPath}' to SHA '{$templateSha}'");
list($output, $returnCode) = $cli->execute('git fetch --all', $templatesCheckoutPath);
list($output, $returnCode) = $cli->execute("git reset --hard origin/master", $templatesCheckoutPath);
list($output, $returnCode) = $cli->execute("git co {$templateSha}", $templatesCheckoutPath);

/**
 * Run packer and capture image region and name
 */
$pathToTemplate = trim("{$templatesCheckoutPath}/{$template}");
if (!file_exists($pathToTemplate)) {
    exit("Path to build template not exists and/or not readable: '{$pathToTemplate}'");
}
$packerConfig = $config->get('packer');

list($result, $returnCode) = $cli->runPackerBuild(
    $packerConfig['executablePath'],
    $pathToTemplate,
    $packerConfig['awsAccessKey'],
    $packerConfig['awsSecretKey']
);

if($returnCode == 0) {
    list($region, $amiId) =  array_map('trim',explode(":", $result[count($result)-1]));
    $executionMetrics['createdAmiRegion'] = $region;
    $executionMetrics['createdAmiId'] = $amiId;
    $logger->info("Packer build succeeded: Region '{$region}', AMI id '{$amiId}'");
    $executionMetrics['endTime'] = microtime(true);
} else {
    $executionMetrics['endedInError'] = true;
    $logger->error("The packer build execution returned non zero result: '{$returnCode}'");
    $logger->error("Last 20 lines of output: ", array_slice($result, -20));
}

$date = date('Y-m-d\TH-i-s', $executionMetrics['startTime']);
$shaForPath = substr($templateSha,0,7);
$s3ObjectPath = "builds/{$template}/{$date}/{$shaForPath}.yml";
$logger->info("Writing results to S3: '{$s3ObjectPath}'");
writeExecutionDigest(
    $aws,
    $s3ObjectPath,
    $config->get('executionDigest'),
    spyc_dump($executionMetrics)
);

/**
 * Construct Job run manifest
 */

/**
 * Put manifest in S3
 * @param \Aws\Sqs\SqsClient $sqsClient
 * @param string $queueName
 */


function deleteQueue(\Aws\Sqs\SqsClient $sqsClient, $queueName)
{
    try {
        $queueUrlResponse = $sqsClient->getQueueUrl(['QueueName' => $queueName]);
        $sqsClient->deleteQueue(['QueueUrl' => $queueUrlResponse->get('QueueUrl')]);
    } catch (\Aws\Sqs\Exception\SqsException $e) {
        exit ("ERROR Deleting Queue '{$queueName}': {$e->getMessage()}");
    } catch (Exception $e) {
        exit ("ERROR Deleting Queue '{$queueName}': {$e->getMessage()}" . "\n");
    }
}

function writeExecutionDigest(\Aws\Common\Aws $aws, $keyName, $digestConfig, $digestContent)
{
    $bucketName = $digestConfig["bucketName"];
    $s3Client = $aws->get('S3');
    try {
        $result = $s3Client->putObject(array(
            'Bucket' => $bucketName,
            'Key'    => $keyName,
            'Body'   => $digestContent
        ));
    } catch (\Aws\S3\Exception\S3Exception $e) {
        exit ("ERROR PUTing to bucket '{$bucketName}': {$e->getMessage()}" . "\n");
    } catch (Exception $e) {
        exit ("ERROR PUTing to bucket '{$bucketName}': {$e->getMessage()}" . "\n");
    }

    return $result;

}

