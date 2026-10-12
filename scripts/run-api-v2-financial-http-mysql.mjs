import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import crypto from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const run=(cmd,args,options={})=>{const r=spawnSync(cmd,args,{encoding:'utf8',windowsHide:true,shell:false,timeout:120000,...options});assert.equal(r.status,0,r.stderr||'local subprocess failed');return r.stdout.trim();};
const suffix=crypto.randomBytes(16).toString('hex'),name='pa-financial-http-test-'+suffix,database='api_v2_financial_test_'+suffix;
const id=run('docker',['run','-d','--name',name,'-e','MYSQL_ROOT_PASSWORD=local-disposable-only','-e','MYSQL_DATABASE='+database,'-p','127.0.0.1::3306','mysql:8.4']);assert.match(id,/^[a-f0-9]{64}$/);
try{
 let ready=false;for(let i=0;i<60;i++){const r=spawnSync('docker',['exec',id,'mysqladmin','-h','127.0.0.1','ping','-plocal-disposable-only'],{encoding:'utf8',windowsHide:true,shell:false,timeout:5000});if(r.status===0){ready=true;break;}await new Promise(resolve=>setTimeout(resolve,500));}assert(ready,'new disposable MySQL not ready');
 const port=run('docker',['port',id,'3306/tcp']);assert.match(port,/^127\.0\.0\.1:[0-9]+$/);
 const env={...process.env,API_V2_FINANCIAL_MYSQL_DSN:`mysql:host=127.0.0.1;port=${port.split(':')[1]};dbname=${database};charset=utf8mb4`,API_V2_FINANCIAL_MYSQL_DATABASE:database,API_V2_FINANCIAL_MYSQL_PASSWORD:'local-disposable-only',API_V2_FINANCIAL_MYSQL_ALLOW_DESTRUCTIVE:'isolated-disposable-only'};
 const result=spawnSync('php',[...(process.platform==='win32'?['-d','extension=sodium']:[]),'vendor/bin/phpunit','tests/Integration/ApiV2FinancialHttpMySqlTest.php','--do-not-cache-result','--colors=never'],{cwd:root,env,windowsHide:true,shell:false,timeout:120000,encoding:'utf8'});process.stdout.write(result.stdout??'');process.stderr.write(result.stderr??'');process.exitCode=result.status??1;
}finally{assert.equal(run('docker',['inspect',id,'--format','{{.Id}} {{.Name}} {{.Config.Image}}']),`${id} /${name} mysql:8.4`);run('docker',['rm','-f','-v',id]);}
