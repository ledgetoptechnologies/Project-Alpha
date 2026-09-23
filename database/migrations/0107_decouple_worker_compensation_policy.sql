-- Account authority and worker relationship do not determine compensation.
-- Preserve every existing policy and historical earning; only change the
-- database default used when a new profile omits an explicit policy.
ALTER TABLE worker_profiles
    MODIFY COLUMN compensation_policy
        ENUM('rules','nonpayable','owner_no_pay','needs_setup','needs_review')
        NOT NULL DEFAULT 'needs_setup';
