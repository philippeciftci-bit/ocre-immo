DELIMITER $$

DROP TRIGGER IF EXISTS trg_agent_pacts_normalize_ins$$
DROP TRIGGER IF EXISTS trg_agent_pacts_normalize_upd$$

CREATE TRIGGER trg_agent_pacts_normalize_ins
BEFORE INSERT ON agent_pacts
FOR EACH ROW
BEGIN
  DECLARE tmp INT;
  IF NEW.agent_a_id > NEW.agent_b_id THEN
    SET tmp = NEW.agent_a_id;
    SET NEW.agent_a_id = NEW.agent_b_id;
    SET NEW.agent_b_id = tmp;
  END IF;
END$$

CREATE TRIGGER trg_agent_pacts_normalize_upd
BEFORE UPDATE ON agent_pacts
FOR EACH ROW
BEGIN
  DECLARE tmp INT;
  IF NEW.agent_a_id > NEW.agent_b_id THEN
    SET tmp = NEW.agent_a_id;
    SET NEW.agent_a_id = NEW.agent_b_id;
    SET NEW.agent_b_id = tmp;
  END IF;
END$$

DELIMITER ;
