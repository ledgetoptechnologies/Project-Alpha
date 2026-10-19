<?php
declare(strict_types=1);
namespace Tests\Workflows;
use PHPUnit\Framework\TestCase;
use App\Services\LocalOperatorKeyReceipt;
require_once dirname(__DIR__,2).'/src/services/ApiV2CapabilitiesKeyProvisioner.php';
require_once dirname(__DIR__,2).'/src/services/LocalOperatorKeyReceipt.php';
final class LocalOperatorKeyReceiptTest extends TestCase
{
    private string $directory;
    protected function setUp():void
    {
        if(!function_exists('posix_geteuid')||posix_geteuid()!==0)self::markTestSkipped('Root CLI filesystem required');
        unset($_SESSION['user']);$this->directory=sys_get_temp_dir().'/pa-capability-receipt-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);
    }
    protected function tearDown():void
    {
        if(!isset($this->directory))return;foreach(glob($this->directory.'/*')?:[] as $path){if(is_dir($path)&&!is_link($path))rmdir($path);else unlink($path);}rmdir($this->directory);
    }
    public function testMaterialIsPrivateImmutableAndOutcomeWritesCannotTruncateIt():void
    {
        $path=$this->directory.'/key.json';$vault=new LocalOperatorKeyReceipt($path);$vault->lock();$material=$vault->create(['version'=>1]);$bytes=file_get_contents($path);
        self::assertSame(0600,fileperms($path)&0777);self::assertMatchesRegularExpression('/^pa_live_[a-f0-9]{12}_[a-f0-9]{64}$/D',$material['secret']);
        $vault->journal(['state'=>'attempting']);$vault->journal(['state'=>'created']);self::assertSame($bytes,file_get_contents($path));self::assertSame($material,$vault->material(['version'=>1]));
        try{$vault->create(['version'=>1]);self::fail('overwritten');}catch(\RuntimeException){self::assertSame($bytes,file_get_contents($path));}
    }
    public function testConcurrentSameFileLockIsExclusive():void
    {
        $path=$this->directory.'/key.json';$one=new LocalOperatorKeyReceipt($path);$one->lock();$two=new LocalOperatorKeyReceipt($path);
        $this->expectExceptionMessage('request_in_progress');$two->lock();
    }
    public function testUnsafeModeAndSymlinksAreRefusedWithoutWriting():void
    {
        $path=$this->directory.'/unsafe';file_put_contents($path,'not secret');chmod($path,0644);
        try{new LocalOperatorKeyReceipt($path);self::fail('public mode');}catch(\RuntimeException $e){self::assertSame('unsafe_private_file',$e->getMessage());}
        symlink($path,$this->directory.'/link');
        $this->expectExceptionMessage('unsafe_private_file');new LocalOperatorKeyReceipt($this->directory.'/link');
    }
    public function testWebrootOutputAndUnreviewedMaterialAreRefused():void
    {
        try{LocalOperatorKeyReceipt::safePath('/var/www/public/private-key.json');self::fail('webroot');}catch(\RuntimeException $e){self::assertSame('webroot_output_forbidden',$e->getMessage());}
        $vault=new LocalOperatorKeyReceipt($this->directory.'/key.json');$vault->lock();$vault->create(['request'=>'one']);
        $this->expectExceptionMessage('private_receipt_request_mismatch');$vault->material(['request'=>'other']);
    }
}
