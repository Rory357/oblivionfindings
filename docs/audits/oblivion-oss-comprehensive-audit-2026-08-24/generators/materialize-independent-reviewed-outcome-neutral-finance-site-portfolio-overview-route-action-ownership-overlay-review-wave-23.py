from __future__ import annotations

import hashlib, json, subprocess
from pathlib import Path

ROOT=Path(__file__).resolve().parents[4]
AUDIT=ROOT/'docs/audits/oblivion-oss-comprehensive-audit-2026-08-24'
PRODUCER_G=AUDIT/'generators/integrate-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.py'
PRODUCER_O=AUDIT/'evidence/source/current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json'
OUTPUT=AUDIT/'evidence/source/raw-run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json'
COMMIT='5bc65efcfa83fb3253b906d8a064fc8addd3789e'; TREE='2109ce64f12bd2d7295fea53133e03a7c6e6c7ea'; PARENT='1f4b83e7fdf97c72e90f2be4c81ada2d3e2017e7'
GEN_SHA='c7c7baeb6f34542911370092ea88e7620142be3863742f73ca1434b91f02f005'; GEN_BLOB='48140e7c4c68242388fbc7f5b75b29a3f3a96b7e'
OUT_SHA='2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb'; OUT_BLOB='02d2c45076e1a8f7c7ef8a7ee10e4bbdb8ad0c6f'

MECH='''{"clean_status":true,"commit":"5bc65efcfa83fb3253b906d8a064fc8addd3789e","diff":{"files":2,"status":"M,M"},"generator":{"blob":"48140e7c4c68242388fbc7f5b75b29a3f3a96b7e","bytes":66545,"lines":746,"sha256":"c7c7baeb6f34542911370092ea88e7620142be3863742f73ca1434b91f02f005"},"inputs":{"count":26,"map_sha256":"aff29ff15eb469fb2365f46078b7ef4a327e138ce70f590859bcd0e70bc29af8","run137r_blob":"495c9faf8b701b0fb1138dc8a80f80cb1f082b92","run137r_sha256":"a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f","undeclared_audit_reads":0},"no_pycache":true,"output":{"blob":"02d2c45076e1a8f7c7ef8a7ee10e4bbdb8ad0c6f","bytes":122380,"lf_no_bom_terminal_lf":true,"lines":1878,"sha256":"2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb","strict_json":true},"parent":"1f4b83e7fdf97c72e90f2be4c81ada2d3e2017e7","replay":{"byte_identical":true,"mode":"in_memory_write_intercept","sha256":"2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb"},"task_path":"/root/run142_overlay_planner","tree":"2109ce64f12bd2d7295fea53133e03a7c6e6c7ea","verdict":"GO","verified":{"assurance_mappings":17,"bridges":93,"counts":35,"expansions":24,"identities":91,"owners":662,"pages":357,"positive_credits":2,"queue_pending":391,"queue_reviewed":116,"residual":3267,"routes":305},"zero_writes":true}'''
LINEAGE='''{"commit":"5bc65efcfa83fb3253b906d8a064fc8addd3789e","diff":{"files":2,"status":"M,M"},"generator":{"blob":"48140e7c4c68242388fbc7f5b75b29a3f3a96b7e","bytes":66545,"lines":746,"sha256":"c7c7baeb6f34542911370092ea88e7620142be3863742f73ca1434b91f02f005"},"inputs":{"count":26,"map_sha256":"aff29ff15eb469fb2365f46078b7ef4a327e138ce70f590859bcd0e70bc29af8","run137r_blob":"495c9faf8b701b0fb1138dc8a80f80cb1f082b92","run137r_sha256":"a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f","undeclared_audit_reads":0},"output":{"blob":"02d2c45076e1a8f7c7ef8a7ee10e4bbdb8ad0c6f","bytes":122380,"lines":1878,"sha256":"2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb"},"parent":"1f4b83e7fdf97c72e90f2be4c81ada2d3e2017e7","positive_credits":["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD","STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"],"task_path":"/root/run142_overlay_planner/run142r_lineage_reviewer","tree":"2109ce64f12bd2d7295fea53133e03a7c6e6c7ea","verdict":"GO","verified":{"assurance_mappings":17,"counts":35,"credit_partition_unchanged":true,"expansions":24,"identities":91,"identity_rediscovery_equal":true,"noninheritance_unchanged":true,"page_and_queue_boundaries_unchanged":true,"reviewer_lineage_unchanged":true},"zero_writes":true}'''
SEMANTIC='''{"review_id":"RUN142R-POST-CORRECTION-SEMANTIC-OWNERSHIP-NONINHERITANCE-REPORTING-PLANNER","reviewer_task_path":"/root/run143_reporting_planner","review_scope":"POST_CORRECTION_POST_COMMIT_SEMANTIC_OWNERSHIP_NONINHERITANCE","reviewed_on":"2026-08-26","verdict":"GO","confidence":"HIGH","producer_commit":"5bc65efcfa83fb3253b906d8a064fc8addd3789e","producer_tree":"2109ce64f12bd2d7295fea53133e03a7c6e6c7ea","producer_parent":"1f4b83e7fdf97c72e90f2be4c81ada2d3e2017e7","generator_sha256":"c7c7baeb6f34542911370092ea88e7620142be3863742f73ca1434b91f02f005","generator_blob_id":"48140e7c4c68242388fbc7f5b75b29a3f3a96b7e","output_sha256":"2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb","output_blob_id":"02d2c45076e1a8f7c7ef8a7ee10e4bbdb8ad0c6f","exact_modified_files":2,"semantic_checks_run":29,"semantic_checks_passed":29,"semantic_discrepancies":0,"provenance_discrepancies":0,"closed_blockers":[{"id":"RUN142R-PROVENANCE-UNPINNED-RAW-RUN137R","corrected_input_path":"evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json","corrected_input_sha256":"a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f","corrected_input_blob_id":"495c9faf8b701b0fb1138dc8a80f80cb1f082b92","corrected_input_count":26,"corrected_input_map_sha256":"aff29ff15eb469fb2365f46078b7ef4a327e138ce70f590859bcd0e70bc29af8","all_literal_read_dependencies_pinned":true}],"verified_outcome":{"route_owner_records":1,"controller_action_bridges":1,"page_owner_records":0,"correctness_or_downstream_credit":false,"source_owner_records":662,"route_owner_records_total":305,"page_owner_records_total":357,"static_controller_action_bridges_total":93,"reviewed_queue_rows":116,"pending_queue_rows":391,"identity_fields":91,"true_credit_keys":["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD","STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"]},"verified_noninheritance":{"existing_page_owner":"PAGE-ROOT-FC2C5F5706FD9066/RUN086-PAGE-MAP-0313","sibling_route":"RUN090-ROUTE-0041/RUN077-ROUTE-0418","page_path_callers":3,"selected_api_exact_frontend_callers":0,"excluded_neighbor_79":"RUN090-ROUTE-0080/RUN077-ROUTE-0688","next_pending_80":"RUN090-ROUTE-0081/RUN077-ROUTE-0689","page_sibling_caller_neighbor_next_credit_inherited":false},"verified_preservation":{"semantic_payload_other_than_pins_and_correction_metadata_changed":false,"source_packet_expansion_files":24,"source_packet_expansion_existing_files":6,"source_packet_expansion_new_files":18,"source_packet_expansion_locus_corrections":1,"assurance_reconciliation_inputs":17,"action_assurance_findings":9,"shared_assurance_findings":3,"two_blinded_reviews_and_distinct_synthesis_preserved":true,"one_operating_organisation_across_multiple_sites_preserved":true},"blockers":[],"reviewer_wrote_files":false,"wrote_files":[]}'''

def sha(b:bytes)->str:return hashlib.sha256(b).hexdigest()
def canon(v:object)->bytes:return json.dumps(v,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
def strict(raw:bytes)->dict:
 def hook(pairs):
  assert len(pairs)==len({k for k,_ in pairs})
  return dict(pairs)
 return json.loads(raw,object_pairs_hook=hook)
def git(*a:str)->str:return subprocess.run(['git',*a],cwd=ROOT,check=True,text=True,capture_output=True).stdout.strip()

def replay_producer_in_memory()->bytes:
 source=PRODUCER_G.read_text(encoding='utf-8').replace('if __name__ == "__main__":\n    main()','')
 ns={'__name__':'run142_in_memory_replay','__file__':str(PRODUCER_G)}; exec(compile(source,str(PRODUCER_G),'exec'),ns)
 real_git=ns['git']; captured:dict[str,bytes]={}
 def replay_git(*args:str)->str:
  if args==('rev-parse','HEAD'):return PARENT
  if args==('show','-s','--format=%T','HEAD'):return '530f9825a389b6135250bc27553e0f5e2c71d572'
  if args==('branch','--show-current'):return 'main'
  if args==('status','--short'):return ' M '+PRODUCER_O.relative_to(ROOT).as_posix()+'\n M '+PRODUCER_G.relative_to(ROOT).as_posix()
  return real_git(*args)
 ns['git']=replay_git
 real_run=subprocess.run
 def replay_run(args,*pos,**kw):
  if args==['git','status','--short']:
   text=' M '+PRODUCER_O.relative_to(ROOT).as_posix()+'\n M '+PRODUCER_G.relative_to(ROOT).as_posix()+'\n'
   return subprocess.CompletedProcess(args,0,stdout=text,stderr='')
  return real_run(args,*pos,**kw)
 real_write=Path.write_bytes; real_read=Path.read_bytes
 def memory_write(self:Path,data:bytes)->int:
  assert self.resolve()==PRODUCER_O.resolve(); captured['output']=bytes(data); return len(data)
 def memory_read(self:Path)->bytes:
  if self.resolve()==PRODUCER_O.resolve() and 'output' in captured:return captured['output']
  return real_read(self)
 Path.write_bytes=memory_write; Path.read_bytes=memory_read; subprocess.run=replay_run
 try: ns['main']()
 finally: Path.write_bytes=real_write; Path.read_bytes=real_read; subprocess.run=real_run
 assert 'output' in captured
 return captured['output']

def main()->None:
 assert git('rev-parse','HEAD')==COMMIT and git('show','-s','--format=%T','HEAD')==TREE and git('rev-parse','HEAD^')==PARENT
 assert sha(PRODUCER_G.read_bytes())==GEN_SHA and sha(PRODUCER_O.read_bytes())==OUT_SHA
 assert git('rev-parse',f'HEAD:{PRODUCER_G.relative_to(ROOT).as_posix()}')==GEN_BLOB and git('rev-parse',f'HEAD:{PRODUCER_O.relative_to(ROOT).as_posix()}')==OUT_BLOB
 assert len(PRODUCER_G.read_bytes())==66545 and len(PRODUCER_G.read_text(encoding='utf-8').splitlines())==746
 assert len(PRODUCER_O.read_bytes())==122380 and len(PRODUCER_O.read_text(encoding='utf-8').splitlines())==1878
 producer=strict(PRODUCER_O.read_bytes()); assert len(producer['pins']['inputs'])==26 and len(producer['identity'])==len(producer['identity_discovery'])==91
 replayed=replay_producer_in_memory(); assert replayed==PRODUCER_O.read_bytes() and sha(replayed)==OUT_SHA
 assert producer['identity']==producer['identity_discovery'] and len(producer['reviewer_lineage']['verified_counts'])==35
 assert len(producer['assurance_findings_preservation']['reconciliation']['input_rows'])==17 and len(producer['source_packet_expansion_preservation']['expanded_files'])==24
 assert [k for k,v in producer['credit_boundary'].items() if v]==['STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD','STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION']
 mech,lineage,semantic=(strict(x.encode()) for x in (MECH,LINEAGE,SEMANTIC))
 assert sha(canon(mech))=='1b722d0a0078cdee6c12338b15df4cb36398eaff5ec3a3e048a9573e52573e95'
 assert sha(canon(lineage))=='56e566df9d41f216ec124c6485a2ce5fb54d4c4c9f1a9307ff57df08d3b5dc00'
 assert sha(canon(semantic))=='cadbcf9b8696c223043143fbc2930898f15aebc6ed2dec83926de1d0e707472c'
 reviews=[{'record':mech,'record_sha256':sha(canon(mech))},{'record':lineage,'record_sha256':sha(canon(lineage))},{'record':semantic,'record_sha256':sha(canon(semantic))}]
 synthesis={'reviewer_task_path':'/root','verdict':'GO','accepted_record_sha256s':[r['record_sha256'] for r in reviews],'distinct_reviewer_task_paths':True,'conclusion_sharing':False,'discrepancies':0,'closed_prior_blocker':'RUN142R-PROVENANCE-UNPINNED-RAW-RUN137R','reporting_materialization_authorized':True,'new_ownership_or_bridge_credit':False,'page_or_correctness_credit':False,'runtime_browser_test_benchmark_final_or_completion_credit':False,'reviewer_wrote_files':False}
 synthesis['synthesis_record_sha256']=sha(canon(synthesis))
 credit={key:False for key in ['new_source_ownership','new_route_ownership','new_page_ownership','new_controller_action_bridge','direct_exact_queue_review','current_overlay_ownership_credit','prior_page_owner_context_inherited_or_recredited','frontend_caller_ownership','complete_route_page_feature_crosswalk','matrix_mutation','canonical_object_ownership_correctness','site_authorization_correctness','permission_correctness','privacy_correctness','direct_object_correctness','query_correctness','projection_correctness','period_correctness','allocation_provenance_or_reversal_correctness','utility_true_up_sign_correctness','response_minimization_correctness','lifecycle_correctness','concurrency_or_idempotency_correctness','event_or_downstream_durability_correctness','runtime','database','build','application_browser','responsive_application','visual_application_workflow','executed_tests','application_source_mutation','benchmark','ease','release','pass','final_finding','completion','audit_complete']}; credit={'INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING':True}|credit
 payload={'schema_version':'run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23-v1','run_id':'RUN-142R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FINANCE-SITE-PORTFOLIO-OVERVIEW-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-23','status':'GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_1_OWNER_1_BRIDGE_BOUNDED_STATIC_ONLY','reviewed_on':'2026-08-26','pins':{'review_checkpoint_commit':COMMIT,'review_checkpoint_tree':TREE,'producer_parent':PARENT,'producer_generator':PRODUCER_G.relative_to(ROOT).as_posix(),'producer_generator_sha256':GEN_SHA,'producer_generator_blob_id':GEN_BLOB,'producer':PRODUCER_O.relative_to(ROOT).as_posix(),'producer_sha256':OUT_SHA,'producer_blob_id':OUT_BLOB,'materializer':Path(__file__).resolve().relative_to(ROOT).as_posix(),'materializer_sha256':sha(Path(__file__).read_bytes()),'inputs':producer['pins']['inputs']},'decision':{'verdict':'GO','independent_reviews':3,'discrepancies':0,'reporting_materialization_authorized':True,'gate_4_complete':False},'review_records':reviews,'synthesis_review':synthesis,'verified_counts':producer['reviewer_lineage']['verified_counts'],'verified_identity':producer['identity'],'verified_lineage_correction':producer['lineage_correction'],'verified_noninheritance':producer['noninheritance_boundary'],'credit_boundary':credit,'mutation_attestation':{'audit_artifacts_only':True,'expected_status_paths':[Path(__file__).resolve().relative_to(ROOT).as_posix(),OUTPUT.relative_to(ROOT).as_posix()],'reviewers_wrote_files':0},'artifact_completion_test_met':True,'audit_completion_test_met':False,'wrote_files':[Path(__file__).resolve().relative_to(ROOT).as_posix(),OUTPUT.relative_to(ROOT).as_posix()]}
 raw=(json.dumps(payload,indent=2,ensure_ascii=False)+'\n').encode(); OUTPUT.write_bytes(raw); assert OUTPUT.read_bytes()==raw and b'\r\n' not in raw and not raw.startswith(b'\xef\xbb\xbf'); strict(raw)
 expected={f'?? {p}' for p in payload['wrote_files']}; assert set(git('status','--short').splitlines())==expected; assert not list(AUDIT.rglob('__pycache__'))
if __name__=='__main__':main()
