#!/usr/bin/env php
<?php

// NOTE: This script is very oldschool and takes the environment as an argument.
// Some day, we could take a shot at cleaning this up.
if ($argc > 1) {
  foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^-/', $arg)) {
      $_SERVER['PHABRICATOR_ENV'] = $arg;
      break;
    }
  }
}

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';
require_once $root.'/externals/mimemailparser/MimeMailParser.class.php';

$args = new PhutilArgumentParser($argv);
$args->parseStandardArguments();
$args->parse(
  array(
    array(
      'name' => 'process-duplicates',
      'help' => pht(
        "Process this message, even if it's a duplicate of another message. ".
        "This is mostly useful when debugging issues with mail routing."),
    ),
    array(
      'name' => 'env',
      'wildcard' => true,
    ),
  ));

$parser = new MimeMailParser();
$parser->setText(file_get_contents('php://stdin'));

$content = array();
foreach (array('text', 'html') as $part) {
  $part_body = $parser->getMessageBody($part);
  $part_headers = $parser->getMessageBodyHeaders($part);
  $content_type = idx($part_headers, 'content-type');
  if (
    !phutil_is_utf8($part_body) &&
    (preg_match('/charset="(.*?)"/', $content_type, $matches) ||
     preg_match('/charset=(\S+)/', $content_type, $matches))
  ) {
    $part_body = phutil_utf8_convert($part_body, 'UTF-8', $matches[1]);
  }
  $content[$part] = $part_body;
}

$headers = $parser->getHeaders();
$headers['subject'] = iconv_mime_decode($headers['subject'], 0, 'UTF-8');
$headers['from'] = iconv_mime_decode($headers['from'], 0, 'UTF-8');

if ($args->getArg('process-duplicates')) {
  $headers['message-id'] = Filesystem::readRandomCharacters(64);
}

$received = new PhabricatorMetaMTAReceivedMail();
$received->setHeaders($headers);
$received->setBodies($content);

$attachments = array();
foreach ($parser->getAttachments() as $attachment) {
  if (preg_match('@text/(plain|html)@', $attachment->getContentType()) &&
      $attachment->getContentDisposition() == 'inline') {
    // If this is an "inline" attachment with some sort of text content-type,
    // do not treat it as a file for attachment. MimeMailParser already picked
    // it up in the getMessageBody() call above. We still want to treat 'inline'
    // attachments with other content types (e.g., images) as attachments.
    continue;
  }

  $file = PhabricatorFile::newFromFileData(
    $attachment->getContent(),
    array(
      'name' => $attachment->getFilename(),
      'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
    ));
  $attachments[] = $file->getPHID();
}

try {
  $received->setAttachments($attachments);
  $received->save();
  $received->processReceivedMail();
} catch (Exception $e) {
  $received
    ->setMessage(pht('EXCEPTION: %s', $e->getMessage()))
    ->save();

  throw $e;
}
