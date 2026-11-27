ALTER TABLE invoice_items
    ADD COLUMN parent_item_id INT NULL,
    ADD CONSTRAINT fk_invoice_items_parent
        FOREIGN KEY (parent_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL;
