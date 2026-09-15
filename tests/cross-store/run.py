#!/usr/bin/env python3
"""Cross-store native fixture. Signed file bundles exercise verification/receive/apply; no HTTPS."""
from pathlib import Path
import concurrent.futures, json, subprocess, time
root = Path(__file__).resolve().parent
run = root / ('run-' + str(time.time_ns()))
run.mkdir(mode=0o700)
state = run / 'state.json'
php = '/tmp/wd29-bridge-tests/php'
def call(platform, action, *extra):
    result = subprocess.run([php, str(root/'store.php'), platform, action, str(state), *map(str,extra)], capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError(f'{platform} {action}: {result.stderr or result.stdout}')
    try:
        return json.loads(result.stdout)
    except Exception:
        raise RuntimeError(f'{platform} {action}: non-JSON output {result.stdout!r} {result.stderr!r}')
def bundle(platform,label):
    path = run / (label+'.json')
    path.write_text(json.dumps(call(platform,'export')))
    path.chmod(0o600)
    return path
def quantities(expected,label):
    states={p:call(p,'status') for p in ['woo','ps']}
    assert all(s['quantity']==expected for s in states.values()), (label,states)
    print(f'PASS {label}: Woo={expected}, PS={expected}',flush=True)
    return states
call('woo','init')
call('ps','receive',bundle('woo','initial'))
quantities(20,'initial snapshot')
# Both processes mutate their native stores before either sees the peer event.
# Only Woo action modifies shared fixture identity state; PS gets a separate copy.
# store.php persists identical preexisting state for PS, so execute PS copy separately.
psstate = run/'ps-state.json'
psstate.write_text(state.read_text())
def ps_sale():
    result=subprocess.run([php,str(root/'store.php'),'ps','sale',str(psstate)],capture_output=True,text=True)
    if result.returncode: raise RuntimeError(result.stderr or result.stdout)
    return json.loads(result.stdout)
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    woo=pool.submit(call,'woo','sale');ps=pool.submit(ps_sale)
    a,b=woo.result(),ps.result()
assert a['quantity']==18 and b['quantity']==17,(a,b)
print('PASS concurrent native source movements: Woo checkout -2; PrestaShop stock -3',flush=True)
w=bundle('woo','concurrent-woo');p=bundle('ps','concurrent-ps')
call('ps','receive',w);call('woo','receive',p)
states=quantities(15,'merged movements and native order mirror')
assert states['ps']['system_movements']==1,states
assert all(int(s['order']['unlinked_lines'])==0 for s in states.values()),states
assert float(states['woo']['order']['total'])==float(states['ps']['order']['total']),states
call('ps','receive',w);call('woo','receive',p)
assert quantities(15,'duplicate signed event replay')['ps']['system_movements']==1
call('woo','cancel');c=bundle('woo','cancel')
call('ps','receive',c);call('ps','receive',c)
assert quantities(17,'Woo native cancellation/restock + mirrored cancellation replay')['ps']['system_movements']==2
call('ps','restore');r=bundle('ps','restore')
call('woo','receive',r);call('woo','receive',r)
assert quantities(20,'PrestaShop native restoration + replay')['ps']['system_movements']==2
print('PASS cross-store suite; fixture results retained in '+str(run),flush=True)
