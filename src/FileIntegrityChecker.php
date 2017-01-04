<?php namespace TSEWEB\FileIntegrityChecker;

/**
 * Class FileIntegrityChecker
 *
 * @package        FileIntegrityChecker
 * @version        1.0
 * @author         Vincent Verbruggen
 * @license        MIT
 */
class FileIntegrityChecker
{
    /**
     * Directory to search files in
     * @var string
     */
    protected $directory = __DIR__;

    /**
     * Filename of the hash file where %s is replaced by the md5 hash of the directory to search in
     * @var string
     */
    protected static $hashfile = 'hashes-%s.ser';
    
    /**
     * Filename of the file containing the changes where %s is replaced by the md5 hash of the directory to search in
     * followed by a dash (-) and datetime
     * @var string
     */
    protected static $changesfile = 'changes-%s.ser';

    /**
     * Directory to store the hash file in
     * @var string
     */
    protected $hashDirectory;

    /**
     * Use compression to store the hashes
     * @var bool
     */
    protected $useCompression = true;

    /**
     * Array containing the paths of the excluded files
     * @var array
     */
    protected $excluded = array();

    /**
     * Holds the last error
     * @var Exception
     */
    protected $lastError = false;

    /**
     * Indicates if this server is running Windows
     * @var bool
     */
    protected $isWindows = false;

    /**
     * Do not check file integrity of files larger than # bytes
     * Set to false to disable maximum filesize limitation
     */
    protected $maxFilesize = false;


    /**
     * Class constructor
     * @param string $directory            Directory to check the integrity of
     * @param string $hashDirectory        Directory to store the hash file in
     */
    public function __construct($directory, $hashDirectory)
    {
        $this->setDirectory($directory);
        $this->setHashDirectory($hashDirectory);
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }


    /**
     * Check if integrity of the files is maintained
     * @return bool
     */
    public function checkIntegrity()
    {
        $changes = $this->getChanges();
        return $this->lastError ? false : !count($changes);
    }


    /**
     * Exclude files
     * @param array $excluded        An array containing the paths of the files to exclude
     * @return FileIntegrityChecker
     */
    public function exclude($excluded)
    {
        foreach ((array) $excluded as $exclude) {
            $this->excluded[] = realpath($exclude);
        }
        return $this;
    }


    /**
     * Returns an \Exception, or false if there were no errors
     * @return bool|\Exception
     */
    public function getError()
    {
        return $this->lastError;
    }


    /**
     * Returns an array containing all the changes, or false if there was a problem with the hashfile.
     * This method also writes to the hash file and if there are changes to a change file.
     * @return array
     */
    public function getChanges()
    {
        $this->lastError = false;

        $hashFile = $this->hashDirectory . DIRECTORY_SEPARATOR . sprintf(self::$hashfile, md5($this->directory)).($this->useCompression ? '.gz' : '');
        $changesFile = $this->hashDirectory . DIRECTORY_SEPARATOR . sprintf(self::$changesfile, md5($this->directory).'-'.date('Ymd-His')).($this->useCompression ? '.gz' : '');
        $hashes = array();
        $changes = array();

        if (file_exists($hashFile)) {
            try {
                $data = file_get_contents($hashFile);
                $hashes = unserialize($this->useCompression ? gzdecode($data) : $data);
                unset($data);
            } catch (\Exception $e) {
                $this->lastError = $e;
                $hashes = array();
            }
        }

        $newHashes = $this->getHashes($this->directory);

        foreach ($newHashes as $filename => $info) {
            if (!isset($hashes[$filename])) {
                $info['status'] = 'added';
                $changes[$filename] = $info;
            } elseif ($hashes[$filename] !== $info) {
                $info['status'] = 'changed: ' . join(', ', array_keys(array_diff_assoc($info, $hashes[$filename])));
                $changes[$filename] = $info;
                unset($hashes[$filename]);
            } else {
                unset($hashes[$filename]);
            }
        }

        foreach ($hashes as $filename => $info) {
            $info['status'] = 'deleted';
            $changes[$filename] = $info;
        }
        unset($hashes);

        file_put_contents(
            $hashFile,
            $this->useCompression ? gzencode(serialize($newHashes), 9) : serialize($newHashes)
        );
        
        if (count($changes)) {
            file_put_contents(
                $changesFile,
                $this->useCompression ? gzencode(serialize($changes), 9) : serialize($changes)
            );
        }

        return $changes;
    }


    /**
     * Check if a file is excluded
     * @param string $filename      Filename to check if it is excluded
     * @return bool
     */
    public function isExcluded($filename)
    {
        if (in_array($filename, $this->excluded)) {
            return true;
        }

        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $filename));
        array_pop($parts);

        $directory = '';
        foreach ($parts as $part) {
            $directory .= DIRECTORY_SEPARATOR . $part;

            if (in_array($this->isWindows ? substr($directory, 1) : $directory, $this->excluded)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Set the directory to check the integrity of
     * @param string $directory                                     Directory to check the integrity of
     * @return \TSEWEB\FileIntegrityChecker\FileIntegrityChecker
     * @throws \Exception                                           Throws an exception if the directory does not
     *                                                               exist or is not readable.
     */
    public function setDirectory($directory)
    {
        if (!file_exists($directory) || !is_dir($directory) || !is_readable($directory)) {
            throw new \Exception('The directory ' . $directory . ' does not exist or is not readable.');
        }
        $this->directory = trim($directory, '/\\');
        return $this;
    }


    /**
     * Set the directory to store the hash file in
     * @param string $directory                                     The directory to store the hash file
     * @return \TSEWEB\FileIntegrityChecker\FileIntegrityChecker
     * @throws \Exception                                           Throws an exception if the directory does not
     *                                                               exist or is not writable.
     */
    public function setHashDirectory($directory)
    {
        if (!file_exists($directory) || !is_writable($directory)) {
            throw new \Exception('The directory ' . $directory . ' does not exist or is not writable.');
        }
        $this->hashDirectory = trim($directory, '/\\');
        return $this;
    }


    /**
     * Do not check file integrity of files larger than # bytes.
     * Set to false to disable maximum filesize limitation.
     * @param bool|int $size        The maximum filesize in bytes
     * @return FileIntegrityChecker
     */
    public function setMaxFilesize($size)
    {
        $this->maxFilesize = $size === false ? false : (int) $size;
        return $this;
    }


    /**
     * Enable or disable compression of the hash file
     * @param bool $bool                Set to true to enable or false to disable
     * @return FileIntegrityChecker
     */
    public function useCompression($bool)
    {
        $this->useCompression = (bool) $bool;
        return $this;
    }


    /**
     * Get the hashes and the file info of all the files
     * @param string $directory
     * @return array
     */
    protected function getHashes($directory)
    {
        $hashes = array();

        $rdi = new \RecursiveDirectoryIterator(realpath($directory));
        foreach (new \RecursiveIteratorIterator($rdi) as $filename => $splFileInfo) {
            $basename = $splFileInfo->getBasename();
            if ($basename !== '.' && $basename !== '..' && !$this->isExcluded($filename)
                && ($this->maxFilesize ? $splFileInfo->getSize() < $this->maxFilesize : true)
            ) {
                $hashes[$filename] = self::getInfo($splFileInfo);
            }
        }

        return $hashes;
    }


    /**
     * Get info about the file
     * @param SplFileInfo $splFileInfo
     * @return array
     */
    protected static function getInfo($splFileInfo)
    {
        $owner = function_exists('posix_getpwuid') ? posix_getpwuid($splFileInfo->getOwner()) : getenv('USERNAME');
        return array(
            'changeTime' => $splFileInfo->getCTime(),
            'ownerUID' => is_array($owner) ? $owner['uid'] : $owner,
            'ownerName' => is_array($owner) ? $owner['name'] : $owner,
            'modifiedTime' => $splFileInfo->getMTime(),
            'permissions' => substr(sprintf('%o', $splFileInfo->getPerms()), -4),
            'size' => $splFileInfo->getSize(),
            'type' => $splFileInfo->getType(),
            'hash' => md5_file($splFileInfo->getPathname()),
        );
    }
}
