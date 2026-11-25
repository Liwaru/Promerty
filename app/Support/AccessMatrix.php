<?php

namespace App\Support;

final class AccessMatrix
{
    public const LABELS = ['customer'=>'Customer','agent'=>'Agent','branch_manager'=>'Branch Manager','admin'=>'Admin','owner'=>'Owner'];

    public const EDITABLE = [
        'customer'=>['interact_posts','message_agents','request_surveys'],
        'agent'=>['interact_posts','message_agents','request_surveys','create_listings','manage_own_listings','create_posts','handle_own_surveys','view_own_analytics'],
        'branch_manager'=>['view_branch_dashboard','view_branch_agents','view_branch_listings'],
    ];

    public const GROUPS = [
        'Umum'=>['interact_posts'=>'Interaksi, Mengikuti, Tersimpan dan Aktivitas','message_agents'=>'Pesan ke Agent','request_surveys'=>'Ajukan Survei','contact_support'=>'Hubungi Bantuan'],
        'Agent'=>['manage_own_listings'=>'Dashboard dan Kelola Listing Sendiri','create_listings'=>'Buat Listing dan Lihat Penugasan Sendiri',
            'create_posts'=>'Kelola Media dan Publikasikan Listing','manage_own_posts'=>'Kelola Post Sendiri','handle_own_surveys'=>'Lihat Survei Listing Sendiri','view_own_analytics'=>'Lihat Statistik Sendiri'],
        'Cabang'=>['view_branch_dashboard'=>'Dashboard dan Aktivitas Cabang','view_branch_agents'=>'Lihat Agent Cabang','view_branch_listings'=>'Lihat Listing Cabang','view_branch_surveys'=>'Akses Survei Cabang'],
        'Administrasi'=>['view_operational_analytics'=>'Ringkasan dan Monitoring Operasional','manage_users'=>'Kelola Akun Pengguna','verify_agents'=>'Verifikasi Agent',
            'manage_master_data'=>'Kelola Katalog dan Proyek','manage_project_assignments'=>'Kelola Penugasan Proyek','moderate_content'=>'Moderasi Konten',
            'manage_branches'=>'Kelola Cabang','manage_agencies'=>'Kelola Agency','manage_permissions'=>'Kelola Hak Akses','view_audit_logs'=>'Lihat Audit Keamanan',
            'manage_system_settings'=>'Pengaturan Sistem','handle_support'=>'Tangani Permintaan Bantuan','restore_content'=>'Pulihkan Konten'],
        'Laporan bisnis'=>['view_global_analytics'=>'Ringkasan Bisnis dan Laporan Cabang'],
    ];
}
