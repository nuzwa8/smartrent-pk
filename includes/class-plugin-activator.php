<?php
/**
 * SmartRent PK Plugin Activator Class
 * (DB) ٹیبلز اور کسٹم رولز کو ہینڈل کرتی ہے۔
 */

// 🟢 یہاں سے Activator Class شروع ہو رہا ہے
class SmartRent_PK_Activator {

    /**
     * پلگ اِن کو چالو کرنے پر چلتا ہے۔
     */
    public static function activate() {
        self::create_custom_roles();
        self::create_database_tables();
        add_option( 'ssm_plugin_version', SSM_PLUGIN_VERSION );
    }

    /**
     * کسٹم یوزر رولز اور صلاحیتیں (Capabilities) بناتا ہے۔
     */
    private static function create_custom_roles() {
        // Core Capabilities:
        $core_caps = [
            'read' => true,
            'ssm_access_admin' => true, // پلگ اِن ایڈمن ایریا تک رسائی
        ];

        // 1. کمپنی ایڈمن (سب سے زیادہ اختیارات)
        add_role(
            'ssm_company_admin',
            esc_html__( 'Company Admin', 'smartrent-pk' ),
            array_merge( $core_caps, [
                'ssm_manage_properties' => true,
                'ssm_manage_estamp'     => true,
                'ssm_manage_trs'        => true,
                'ssm_manage_sla'        => true,
                'ssm_full_admin'        => true,
                'manage_options'        => true, // سیٹنگز تک رسائی
            ])
        );

        // 2. مالک (Owner)
        add_role(
            'ssm_owner',
            esc_html__( 'Property Owner', 'smartrent-pk' ),
            array_merge( $core_caps, [
                'ssm_manage_properties' => true,
                'ssm_view_reports'      => true,
                'ssm_download_wht'      => true,
            ])
        );

        // 3. کرایہ دار (Tenant)
        add_role(
            'ssm_tenant',
            esc_html__( 'Tenant', 'smartrent-pk' ),
            array_merge( $core_caps, [
                'ssm_pay_rent'          => true,
                'ssm_view_invoices'     => true,
                'ssm_update_cnic'       => true,
            ])
        );
    }

    /**
     * اہم (SQL) ڈیٹا بیس ٹیبلز بناتا ہے۔
     */
    private static function create_database_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // کرایہ دار کی تفصیلات اور تصدیق کا لاگ (NADRA/TRS)
        $table_name = $wpdb->prefix . 'ssm_tenants';
        $charset_collate = $wpdb->get_charset_collate();

        // 🟢 یہاں سے SQL Table Definition شروع ہو رہا ہے
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tenant_name varchar(255) NOT NULL,
            cnic_number varchar(15) NOT NULL UNIQUE,
            property_id mediumint(9) NOT NULL,
            verification_status varchar(50) DEFAULT 'Pending' NOT NULL,
            nadra_status varchar(50) DEFAULT 'Pending' NOT NULL,
            trs_status varchar(50) DEFAULT 'Pending' NOT NULL,
            date_created datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY property_id (property_id)
        ) $charset_collate;";
        // 🔴 یہاں پر SQL Table Definition ختم ہو رہا ہے

        dbDelta( $sql );
    }
}
// 🔴 یہاں پر Activator Class ختم ہو رہا ہے
// ✅ Syntax verified block end.
