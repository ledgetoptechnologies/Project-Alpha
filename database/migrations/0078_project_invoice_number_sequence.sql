-- Serialize visible Project Invoice number allocation across all projects.
INSERT INTO document_number_sequences (document_type,document_subtype,next_number)
SELECT 'project_invoice','standard',COALESCE(MAX(doc_number),0)+1 FROM project_invoices
ON DUPLICATE KEY UPDATE next_number=GREATEST(next_number,VALUES(next_number));
