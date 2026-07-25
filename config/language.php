<?php
/**
 * Language Configuration
 * Supports English and Twi (Ghanaian)
 */

// Set default language
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'en'; // Default to English
}

// Language strings
$lang = [
    'en' => [
        // Login Page
        'school_management' => 'School Based Assessment System',
        'select_school_type' => 'Select School Type',
        'primary_school' => 'Primary School',
        'jhs' => 'Junior High School (JHS)',
        'basic_school' => 'Basic School',
        'continue' => 'Continue',
        'login' => 'Login',
        'username' => 'Username',
        'password' => 'Password',
        'developer_login' => 'Developer Login',
        'change_school_type' => 'Change School Type',
        
        // Dashboard
        'dashboard' => 'Dashboard',
        'welcome' => 'Welcome',
        'total_students' => 'Total Students',
        'total_classes' => 'Total Classes',
        'total_subjects' => 'Total Subjects',
        'students' => 'Students',
        'scores' => 'Scores',
        'broadsheet' => 'Broadsheet',
        'result_analysis' => 'Result Analysis',
        'student_report' => 'Student Report',
        'settings' => 'Settings',
        'logout' => 'Logout',
        
        // Student Management
        'add_student' => 'Add Student',
        'student_name' => 'Student Name',
        'student_id' => 'Student ID',
        'class' => 'Class',
        'gender' => 'Gender',
        'male' => 'Male',
        'female' => 'Female',
        'attendance' => 'Attendance',
        'interest' => 'Interest',
        'conduct' => 'Conduct',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'save' => 'Save',
        'cancel' => 'Cancel',
        
        // Scores
        'subject' => 'Subject',
        'test1' => 'Test 1',
        'group_work' => 'Group Work',
        'test2' => 'Test 2',
        'project_work' => 'Project Work',
        'exam_score' => 'Exam Score',
        'total_score' => 'Total Score',
        'position' => 'Position',
        'grade' => 'Grade',
        
        // Report
        'form_master_remarks' => 'Form Master Remarks',
        'headmaster_remarks' => 'Headmaster Remarks',
        'promoted' => 'Promoted',
        'promoted_to' => 'Promoted To',
        'academic_year' => 'Academic Year',
        'current_term' => 'Current Term',
        'reopen_date' => 'Reopening Date',
        
        // Actions
        'search' => 'Search',
        'print' => 'Print',
        'export' => 'Export',
        'import' => 'Import',
        'submit' => 'Submit',
        'update' => 'Update',
        
        // Messages
        'success' => 'Success',
        'error' => 'Error',
        'loading' => 'Loading...',
        'no_data' => 'No data available',
        'confirm_delete' => 'Are you sure you want to delete this?',
    ],
    
    'tw' => [ // Twi language
        // Login Page
        'school_management' => 'Sukuu Nhyehyɛeɛ Nhyehyɛeɛ',
        'select_school_type' => 'Paw Sukuu Suhuo',
        'primary_school' => 'Mfitiaseɛ Sukuu',
        'jhs' => 'Ntoasoɔ Sukuu (JHS)',
        'basic_school' => 'Mfitiaseɛ Sukuu',
        'continue' => 'Kɔ So',
        'login' => 'Kɔ Mu',
        'username' => 'Wo Din',
        'password' => 'Ahintasɛm',
        'developer_login' => 'Nhyehyɛeɛni Kɔ Mu',
        'change_school_type' => 'Sesa Sukuu Suhuo',
        
        // Dashboard
        'dashboard' => 'Mfitiaseɛ Krataafa',
        'welcome' => 'Akwaaba',
        'total_students' => 'Asuafoɔ Nyinaa',
        'total_classes' => 'Adesuafoɔ Nyinaa',
        'total_subjects' => 'Adesua Nyinaa',
        'students' => 'Asuafoɔ',
        'scores' => 'Nkontaabu',
        'broadsheet' => 'Nkontaabu Nhoma',
        'result_analysis' => 'Nkontaabu Nhwehwɛmu',
        'student_report' => 'Osuani Amanneɛbɔ',
        'settings' => 'Nhyehyɛeɛ',
        'logout' => 'Fi Adi',
        
        // Student Management
        'add_student' => 'Fa Osuani Ka Ho',
        'student_name' => 'Osuani Din',
        'student_id' => 'Osuani Nɔma',
        'class' => 'Adesuafoɔ',
        'gender' => 'Nipa Suhuo',
        'male' => 'Barima',
        'female' => 'Basia',
        'attendance' => 'Abadeɛ',
        'interest' => 'Anigye',
        'conduct' => 'Suban',
        'edit' => 'Sesa',
        'delete' => 'Yi Fi',
        'save' => 'Kora',
        'cancel' => 'Gyae',
        
        // Scores
        'subject' => 'Adesua',
        'test1' => 'Sɔhwɛ 1',
        'group_work' => 'Kuo Adwuma',
        'test2' => 'Sɔhwɛ 2',
        'project_work' => 'Dwumadie Adwuma',
        'exam_score' => 'Sɔhwɛ Kɛseɛ Nkontaabu',
        'total_score' => 'Nkontaabu Nyinaa',
        'position' => 'Baabi',
        'grade' => 'Kɔntaabudie',
        
        // Report
        'form_master_remarks' => 'Adesuafoɔ Panin Nsɛm',
        'headmaster_remarks' => 'Sukuupanin Nsɛm',
        'promoted' => 'Wama No Kɔ Anim',
        'promoted_to' => 'Wama No Kɔ',
        'academic_year' => 'Adesua Afe',
        'current_term' => 'Bere Yi Nna',
        'reopen_date' => 'Bere A Wɔbɛbue Bio',
        
        // Actions
        'search' => 'Hwehwɛ',
        'print' => 'Tintim',
        'export' => 'Yi Fi',
        'import' => 'Fa Kɔ Mu',
        'submit' => 'De Ma',
        'update' => 'Foforo',
        
        // Messages
        'success' => 'Ɛyɛɛ Yie',
        'error' => 'Mfomsoɔ',
        'loading' => 'Ɛreboa...',
        'no_data' => 'Nsɛm Biara Nni Hɔ',
        'confirm_delete' => 'Wopɛ Sɛ Woyi Eyi Fi Ampa?',
    ]
];

/**
 * Get translated text
 * @param string $key Translation key
 * @return string Translated text
 */
function __($key) {
    global $lang;
    $language = $_SESSION['language'] ?? 'en';
    return $lang[$language][$key] ?? $key;
}

/**
 * Get current language
 * @return string Current language code
 */
function getCurrentLanguage() {
    return $_SESSION['language'] ?? 'en';
}

/**
 * Set language
 * @param string $languageCode Language code ('en' or 'tw')
 */
function setLanguage($languageCode) {
    if (in_array($languageCode, ['en', 'tw'])) {
        $_SESSION['language'] = $languageCode;
    }
}
