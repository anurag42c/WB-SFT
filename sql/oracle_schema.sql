-- Run as the Oracle user that the weighbridge will connect with (or a DBA, then GRANT).
-- Tested syntax: Oracle 11g and later.

CREATE TABLE WEIGHBRIDGE_TICKETS (
    TICKET_NO         VARCHAR2(30)   NOT NULL,
    VEHICLE_NO        VARCHAR2(30)   NOT NULL,
    PARTY             VARCHAR2(200),
    MATERIAL          VARCHAR2(200),
    DIRECTION         VARCHAR2(10),
    DRIVER            VARCHAR2(100),
    CHALLAN_NO        VARCHAR2(60),
    REMARKS           VARCHAR2(500),
    GROSS_KG          NUMBER(12,2),
    TARE_KG           NUMBER(12,2),
    NET_KG            NUMBER(12,2),
    FIRST_WEIGHT_AT   TIMESTAMP,
    SECOND_WEIGHT_AT  TIMESTAMP,
    STATUS            VARCHAR2(12),
    OPERATOR          VARCHAR2(50),
    LOCAL_ID          NUMBER(12),
    SCALE_NAME        VARCHAR2(100),
    PLATE_IN          VARCHAR2(30),
    PLATE_OUT         VARCHAR2(30),
    PLATE_FLAG        VARCHAR2(12),
    SYNCED_AT         TIMESTAMP DEFAULT SYSTIMESTAMP,
    CONSTRAINT PK_WB_TICKETS PRIMARY KEY (TICKET_NO)
);

CREATE INDEX IX_WB_TICKETS_VEH  ON WEIGHBRIDGE_TICKETS (VEHICLE_NO);
CREATE INDEX IX_WB_TICKETS_DATE ON WEIGHBRIDGE_TICKETS (SECOND_WEIGHT_AT);

-- Optional: dedicated least-privilege user (run as DBA)
-- CREATE USER wb_sync IDENTIFIED BY "ChangeMe#123" DEFAULT TABLESPACE users QUOTA 500M ON users;
-- GRANT CREATE SESSION TO wb_sync;
-- Then create the table above while connected as wb_sync.
-- The app only needs SELECT/INSERT/UPDATE on WEIGHBRIDGE_TICKETS and SELECT on V$VERSION (for the Test button; optional).

-- UPGRADE for a table created with an earlier version of this file (adds multi-scale + ANPR columns):
-- ALTER TABLE WEIGHBRIDGE_TICKETS ADD (SCALE_NAME VARCHAR2(100), PLATE_IN VARCHAR2(30), PLATE_OUT VARCHAR2(30), PLATE_FLAG VARCHAR2(12));


-- ---------------------------------------------------------------------------------------------------
-- Invoice / material-return documents (Setup > Documents > "Send verified documents to" Oracle)
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE WB_DOCUMENTS (
    DOC_NO           VARCHAR2(30)   NOT NULL,
    DOC_TYPE         VARCHAR2(20),          -- PO_INVOICE | MATERIAL_RETURN
    INVOICE_NO       VARCHAR2(60),
    INVOICE_DATE     DATE,
    SUPPLIER         VARCHAR2(200),
    SUPPLIER_TAX_ID  VARCHAR2(40),
    BUYER            VARCHAR2(200),
    PO_NO            VARCHAR2(60),
    REF_NO           VARCHAR2(60),
    ORIG_INVOICE_NO  VARCHAR2(60),
    VEHICLE_NO       VARCHAR2(30),
    EWAY_NO          VARCHAR2(40),
    CURRENCY         VARCHAR2(5),
    SUBTOTAL         NUMBER(15,2),
    TAX_AMOUNT       NUMBER(15,2),
    TOTAL_AMOUNT     NUMBER(15,2),
    MATCH_STATUS     VARCHAR2(12),          -- MATCHED | REVIEW | EXCEPTION | UNMATCHED
    WB_TICKETS       VARCHAR2(500),         -- weighbridge ticket numbers, comma separated
    WB_NET_KG        NUMBER(12,2),          -- total weighbridge net weight of those tickets
    EXC_HIGH         NUMBER(4),             -- open HIGH exceptions
    EXC_WARN         NUMBER(4),
    EXC_SUMMARY      VARCHAR2(1000),        -- codes of open exceptions
    VERIFIED_BY      VARCHAR2(50),
    VERIFIED_AT      TIMESTAMP,
    SYNCED_AT        TIMESTAMP DEFAULT SYSTIMESTAMP,
    CONSTRAINT PK_WB_DOCUMENTS PRIMARY KEY (DOC_NO)
);
CREATE INDEX IX_WB_DOCUMENTS_INV ON WB_DOCUMENTS (INVOICE_NO);
CREATE INDEX IX_WB_DOCUMENTS_PO  ON WB_DOCUMENTS (PO_NO);

CREATE TABLE WB_DOCUMENT_LINES (
    DOC_NO         VARCHAR2(30) NOT NULL,
    LINE_NO        NUMBER(4)    NOT NULL,
    MATERIAL_CODE  VARCHAR2(60),
    DESCRIPTION    VARCHAR2(300),
    HSN            VARCHAR2(20),
    QTY            NUMBER(15,3),
    UOM            VARCHAR2(10),
    RATE           NUMBER(15,4),
    AMOUNT         NUMBER(15,2),
    QTY_KG         NUMBER(15,3),
    CONSTRAINT PK_WB_DOCUMENT_LINES PRIMARY KEY (DOC_NO, LINE_NO)
);
