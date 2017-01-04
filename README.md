# File Integrity Checker

File Integrity Checker for PHP applications

## Install

Using composer:
```
composer require tseweb/file-integrity-checker
```

## Usage

```
<?php
$fic = new TSEWEB\FileIntegrityChecker\FileIntegrityChecker('/path/to/document/root', '/outside-document-root/file-integrity/');
$fic->exclude(array(
    './cache',
    './temp',
));
$changes = $fic->getChanges();

if ($changes!==false) {
	// Mail changes to administrator
}
```
