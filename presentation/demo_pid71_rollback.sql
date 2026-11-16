-- Undo everything the PID 71 demo setup did (keeps the project's pre-existing record 1).
UPDATE redcap_external_module_settings SET value='false'
 WHERE external_module_id=39 AND project_id IS NULL AND `key`='enabled';
DELETE FROM redcap_external_module_settings
 WHERE external_module_id=39 AND project_id=71 AND `key`='project_agent_tool_em_prefixes';
DELETE FROM redcap_external_module_settings
 WHERE external_module_id=40 AND project_id=71 AND `key`='agent_mode';
DELETE FROM redcap_external_module_settings
 WHERE external_module_id=82 AND project_id=71 AND `key`='enabled';
DELETE FROM redcap_user_rights WHERE project_id=71 AND username='alice';
DELETE FROM redcap_data3 WHERE project_id=71 AND record BETWEEN '1001' AND '1110';
DELETE FROM redcap_record_list WHERE project_id=71 AND record BETWEEN '1001' AND '1110';
DELETE FROM redcap_metadata WHERE project_id=71 AND field_name IN ('ae_date','ae_severity','ae_outcome');
UPDATE redcap_metadata SET field_order=3 WHERE project_id=71 AND field_name='form_1_complete';
DELETE FROM redcap_external_module_settings WHERE external_module_id=40 AND project_id=71 AND `key`='project_chatbot_title';
