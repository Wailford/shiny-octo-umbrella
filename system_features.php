<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Features & User Guide - School Based Assessment System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #2d3748;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .header p {
            font-size: 1.2rem;
            opacity: 0.95;
        }
        
        .close-btn {
            position: sticky;
            top: 0;
            background: white;
            padding: 15px 30px;
            border-bottom: 3px solid #667eea;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .close-btn a {
            display: inline-block;
            padding: 10px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .close-btn a:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .section {
            margin-bottom: 50px;
        }
        
        .section-title {
            font-size: 2rem;
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-subtitle {
            font-size: 1.5rem;
            color: #764ba2;
            margin: 30px 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .feature-card {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .feature-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .feature-card h3 {
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 1.3rem;
        }
        
        .feature-card p {
            color: #4a5568;
            margin-bottom: 10px;
        }
        
        .feature-card ul {
            margin-left: 20px;
            color: #4a5568;
        }
        
        .feature-card ul li {
            margin-bottom: 8px;
        }
        
        .steps {
            background: #edf2f7;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .steps ol {
            margin-left: 20px;
        }
        
        .steps ol li {
            margin-bottom: 12px;
            padding-left: 10px;
        }
        
        .steps ol li strong {
            color: #667eea;
        }
        
        .highlight-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin: 30px 0;
            text-align: center;
        }
        
        .highlight-box h3 {
            font-size: 1.8rem;
            margin-bottom: 15px;
        }
        
        .highlight-box p {
            font-size: 1.1rem;
            line-height: 1.8;
        }
        
        .pricing-box {
            background: #27ae60;
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin: 30px 0;
            text-align: center;
        }
        
        .pricing-box h3 {
            font-size: 2rem;
            margin-bottom: 20px;
        }
        
        .pricing-box .price {
            font-size: 3rem;
            font-weight: bold;
            margin: 20px 0;
        }
        
        .pricing-box ul {
            list-style: none;
            text-align: left;
            max-width: 600px;
            margin: 20px auto;
        }
        
        .pricing-box ul li {
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .pricing-box ul li:before {
            content: "✅ ";
            margin-right: 10px;
        }
        
        .contact-box {
            background: #3498db;
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            margin: 30px 0;
        }
        
        .contact-box h3 {
            font-size: 1.8rem;
            margin-bottom: 15px;
        }
        
        .contact-box .phone {
            font-size: 1.8rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .grid-item {
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
        }
        
        .grid-item h4 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 1.2rem;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 12px;
            background: #667eea;
            color: white;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
        }
        
        .badge-success {
            background: #27ae60;
        }
        
        .badge-warning {
            background: #f39c12;
        }
        
        .badge-info {
            background: #3498db;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .header p {
                font-size: 1rem;
            }
            
            .content {
                padding: 20px 15px;
            }
            
            .section-title {
                font-size: 1.5rem;
            }
            
            .section-subtitle {
                font-size: 1.2rem;
            }
            
            .grid {
                grid-template-columns: 1fr;
            }
            
            .pricing-box .price {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 School Based Assessment System</h1>
            <p>Complete Features Guide & User Manual</p>
        </div>
        
        <div class="close-btn">
            <strong style="color: #667eea;">📚 Comprehensive System Documentation</strong>
            <a href="login.php">← Back to Login</a>
        </div>
        
        <div class="content">
            <!-- Introduction -->
            <div class="highlight-box">
                <h3>🌟 Welcome to Ghana's Most Comprehensive School Based Assessment System</h3>
                <p>Built specifically for Basic Schools in Ghana with full support for Primary, JHS, and BECE Mock Exam preparation. Pay once, use forever with no monthly fees!</p>
            </div>
            
            <!-- Quick Start -->
            <section class="section">
                <h2 class="section-title">🚀 Getting Started</h2>
                
                <div class="steps">
                    <h3 style="color: #667eea; margin-bottom: 15px;">Registration Process</h3>
                    <ol>
                        <li><strong>Register Your School:</strong> Click "Register Your School" button on login page</li>
                        <li><strong>Fill Information:</strong> Provide school details, admin name, and create login credentials</li>
                        <li><strong>Wait for Approval:</strong> Developer reviews and approves within 24 hours</li>
                        <li><strong>Get 3-Day Trial:</strong> Full system access immediately after approval</li>
                        <li><strong>Pay Once:</strong> Contact developer to pay for lifetime access (no recurring fees!)</li>
                    </ol>
                </div>
                
                <div class="feature-card">
                    <h3>✨ First Login Steps</h3>
                    <p>After approval and first login:</p>
                    <ul>
                        <li>Go to <strong>Settings</strong> and update your school information</li>
                        <li>Upload your school logo for professional reports</li>
                        <li>Add district and circuit information for BECE reports</li>
                        <li>Set current academic year and term</li>
                        <li>Change default passwords for security</li>
                    </ul>
                </div>
            </section>
            
            <!-- Core Features -->
            <section class="section">
                <h2 class="section-title">💼 Core System Features</h2>
                
                <h3 class="section-subtitle">👥 1. Student Management</h3>
                <div class="feature-card">
                    <h3>Add & Manage Students</h3>
                    <p><strong>Location:</strong> Dashboard → Student Records</p>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li><strong>Add Single Student:</strong> Click "Add New Student" button, fill form (name, ID, class, gender, attendance, conduct)</li>
                        <li><strong>Upload Photo:</strong> Click student photo area to upload image</li>
                        <li><strong>Edit Student:</strong> Click "Edit" button next to student name</li>
                        <li><strong>Delete Student:</strong> Click "Delete" button (confirmation required)</li>
                        <li><strong>Search Students:</strong> Use search box to find by name, ID, or class</li>
                        <li><strong>Bulk Import:</strong> Click "Import Students" to upload CSV/Excel file</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Class Promotion</h3>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li>Select students to promote using checkboxes</li>
                        <li>Click "Promote Selected" button</li>
                        <li>Choose destination class (e.g., Basic 8 → Basic 9)</li>
                        <li>Confirm promotion</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>BECE Candidate Index Numbers</h3>
                    <p><strong>For Basic 9 Students:</strong></p>
                    <ul>
                        <li>Add index number when creating/editing Basic 9 students</li>
                        <li>Required for mock exam analysis and BECE reports</li>
                        <li>System automatically uses index numbers in mock exam mode</li>
                    </ul>
                </div>
                
                <h3 class="section-subtitle">📝 2. Score Entry System</h3>
                <div class="feature-card">
                    <h3>🎯 NEW! Multi-Stream Teacher Interface</h3>
                    <p><strong>For Schools with Multiple Streams (A, B, C, D):</strong></p>
                    <ul>
                        <li><strong>Smart Login:</strong> When a teacher logs in, system automatically shows all classes they teach</li>
                        <li><strong>No Re-login Needed:</strong> Switch between classes without entering password again (if same subject password)</li>
                        <li><strong>Stream Labels:</strong> Clear display of which stream you're working with (Basic 7 A, Basic 7 B, etc.)</li>
                        <li><strong>Automatic Detection:</strong> Stream features only appear if your school has multiple streams</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>🎴 NEW! Card-Based Navigation (Dual-Role Teachers)</h3>
                    <p><strong>For Teachers Who Teach AND Manage a Class:</strong></p>
                    <ul>
                        <li><strong>Teaching Cards:</strong> Visual cards showing all subjects you teach across different classes</li>
                        <li><strong>Click to Enter Scores:</strong> Click any card to instantly jump to score entry for that subject/class</li>
                        <li><strong>Color-Coded:</strong> Easy to identify your teaching assignments at a glance</li>
                        <li><strong>Progress Indicators:</strong> See which classes have scores entered</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>👀 NEW! Form Master Monitoring Dashboard</h3>
                    <p><strong>For Form Masters/Class Teachers:</strong></p>
                    <ul>
                        <li><strong>Monitor All Subjects:</strong> View score entry status for ALL subjects in your form class</li>
                        <li><strong>Dropdown Selection:</strong> Quick dropdown to check any subject (even ones you don't teach)</li>
                        <li><strong>View-Only Mode:</strong> When viewing subjects you don't teach:
                            <ul>
                                <li>Green banner indicates "Monitoring Mode"</li>
                                <li>All inputs are readonly (can't accidentally change scores)</li>
                                <li>Can see which teachers have completed score entry</li>
                                <li>Perfect for checking progress before term ends</li>
                            </ul>
                        </li>
                        <li><strong>Full Dashboard Access:</strong> Access class reports, broadsheets, and student reports</li>
                        <li><strong>No Promotion Access:</strong> Form masters cannot promote students (admin only)</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Enter Student Scores (Standard Method)</h3>
                    <p><strong>Location:</strong> Navigation Menu → Score Entry</p>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li><strong>Select Class:</strong> Choose class from dropdown (Basic 7, 8, 9, etc.)</li>
                        <li><strong>Select Subject:</strong> Choose subject you're teaching</li>
                        <li><strong>Enter Subject Password:</strong> Use password assigned to that subject</li>
                        <li><strong>Choose View Mode:</strong>
                            <ul>
                                <li><strong>Single Student:</strong> Enter scores one student at a time with detailed view</li>
                                <li><strong>All Students:</strong> See entire class in table format for bulk entry</li>
                            </ul>
                        </li>
                        <li><strong>Enter Scores:</strong>
                            <ul>
                                <li><strong>Test 1:</strong> First assessment (0-100)</li>
                                <li><strong>Test 2:</strong> Second assessment (0-100)</li>
                                <li><strong>Test 3:</strong> Third assessment (0-100)</li>
                                <li><strong>Test 4:</strong> Fourth assessment (0-100)</li>
                                <li><strong>Exam Score:</strong> End of term exam (0-100)</li>
                                <li>System auto-calculates total and grade (1-9 scale)</li>
                            </ul>
                        </li>
                        <li><strong>Add Remark:</strong> Optional teacher comment on performance</li>
                        <li><strong>Save:</strong> Click "Submit Score" button</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>✨ Enhanced UI Features</h3>
                    <ul>
                        <li><strong>Smooth Scrolling:</strong> Page scrolls smoothly when navigating</li>
                        <li><strong>Scroll Position Memory:</strong> Returns to exact position after saving scores</li>
                        <li><strong>Clear Visual Feedback:</strong> Success/error messages with color coding</li>
                        <li><strong>Mobile Optimized:</strong> Touch-friendly buttons and responsive design</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Mock Exam Mode (Basic 9 Only)</h3>
                    <p><strong>How to activate:</strong></p>
                    <ul>
                        <li>Go to <strong>Settings → Mock Exam Settings</strong></li>
                        <li>Enable "Mock Exam Mode for Basic 9"</li>
                        <li>When enabled:
                            <ul>
                                <li>Students identified by candidate index numbers</li>
                                <li>100-point scoring system</li>
                                <li>Grades calculated on 1-9 scale</li>
                                <li>Special BECE analysis available</li>
                            </ul>
                        </li>
                    </ul>
                </div>
                
                <h3 class="section-subtitle">📊 3. Reports & Analysis</h3>
                
                <div class="feature-card">
                    <h3>Class Broadsheet</h3>
                    <p><strong>Location:</strong> Navigation Menu → Broadsheet</p>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li>Select class from dropdown</li>
                        <li>View complete class performance in table format</li>
                        <li>Shows all subjects, total scores, and class positions</li>
                        <li>Click "Print Broadsheet" to print or save as PDF</li>
                        <li>Students automatically ranked (1st, 2nd, 3rd...)</li>
                    </ul>
                    <p><strong>What it shows:</strong> Student names, all subject scores, totals, averages, and positions</p>
                </div>
                
                <div class="feature-card">
                    <h3>Result Analysis</h3>
                    <p><strong>Location:</strong> Navigation Menu → Result Analysis</p>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li>Select class to analyze</li>
                        <li>View comprehensive performance statistics</li>
                        <li>See grade distribution (1-9 scale)</li>
                        <li>Aggregate analysis (6-48 scale for BECE)</li>
                        <li>Top 10 and Bottom 10 performers</li>
                        <li>Gender-based comparison</li>
                        <li>Pass/fail percentages</li>
                        <li>Click "Export Detailed PDF" to download report</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Student Report Cards</h3>
                    <p><strong>Location:</strong> Navigation Menu → Student Reports</p>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li>Select class from dropdown</li>
                        <li>Choose individual student or "All Students"</li>
                        <li>Click "Generate Report"</li>
                        <li>Report shows:
                            <ul>
                                <li>School logo and information</li>
                                <li>Student details and photo</li>
                                <li>All subject scores and grades</li>
                                <li>Class position and total score</li>
                                <li>Attendance and conduct records</li>
                                <li>Teacher remarks</li>
                            </ul>
                        </li>
                        <li>Click "Print" or "Download PDF" for physical copies</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>🎯 BECE Mock Analysis (Special Feature!)</h3>
                    <p><strong>Location:</strong> Score Entry Page (when viewing Basic 9 mock scores)</p>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li>Enable Mock Exam Mode in Settings</li>
                        <li>Enter all mock exam scores for Basic 9 students</li>
                        <li>Click "View Mock Analysis" button</li>
                        <li>Get traditional BECE-style analysis report with:
                            <ul>
                                <li>12 comprehensive sections</li>
                                <li>District/Circuit headers</li>
                                <li>Subject grade distribution</li>
                                <li>Aggregate statistics (6-30, 6-36, 6-40 pass rates)</li>
                                <li>Gender performance comparison</li>
                                <li>Top 10 and Bottom 10 candidates</li>
                                <li>Detailed frequency tables</li>
                            </ul>
                        </li>
                        <li>Download as landscape PDF for printing</li>
                    </ul>
                    <p><strong>Perfect for:</strong> BECE preparation, identifying weak areas, parent meetings, GES submissions</p>
                </div>
            </section>
            
            <!-- User Roles -->
            <section class="section">
                <h2 class="section-title">👤 User Roles & Permissions (Updated 2.0)</h2>
                
                <div class="grid">
                    <div class="grid-item">
                        <h4>🔑 Admin</h4>
                        <p><strong>Full System Access:</strong></p>
                        <ul>
                            <li>Manage all students (add, edit, delete)</li>
                            <li>Enter scores for any subject</li>
                            <li>View all reports and analytics</li>
                            <li>Create and manage teacher accounts</li>
                            <li>Assign subject teachers to classes</li>
                            <li>Assign form masters to classes</li>
                            <li>Change all system settings</li>
                            <li>Promote students to next class</li>
                            <li>Access BECE mock analysis</li>
                            <li>Manage school information and logo</li>
                        </ul>
                    </div>
                    
                    <div class="grid-item">
                        <h4>👨‍🏫 Subject Master (Teacher)</h4>
                        <p><strong>Teaching-Only Access:</strong></p>
                        <ul>
                            <li>Enter scores for assigned subjects only</li>
                            <li>View subjects across multiple classes</li>
                            <li>Card-based navigation for teaching assignments</li>
                            <li>Add teacher remarks to scores</li>
                            <li>View subject-specific reports</li>
                            <li>Requires subject password for score entry</li>
                            <li><strong>Cannot:</strong> Manage students, promote classes, or change settings</li>
                        </ul>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🎓 Form Master (Class Teacher Only)</h4>
                        <p><strong>Class Management Access:</strong></p>
                        <ul>
                            <li>View assigned class dashboard</li>
                            <li>Generate class broadsheets</li>
                            <li>Generate student report cards</li>
                            <li>View result analysis for class</li>
                            <li>Monitor score entry progress</li>
                            <li><strong>Cannot:</strong> Enter scores, manage students, or promote</li>
                        </ul>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🌟 Dual-Role Teacher (NEW!)</h4>
                        <p><strong>Combined Access:</strong></p>
                        <ul>
                            <li><strong>As Subject Teacher:</strong>
                                <ul>
                                    <li>See teaching cards for all assigned subjects</li>
                                    <li>Enter scores with full edit access</li>
                                    <li>Work across multiple classes</li>
                                </ul>
                            </li>
                            <li><strong>As Form Master:</strong>
                                <ul>
                                    <li>Monitor dropdown for other subjects in form class</li>
                                    <li>View-only access to subjects you don't teach</li>
                                    <li>Full dashboard access for assigned class</li>
                                    <li>Generate all reports for class</li>
                                </ul>
                            </li>
                            <li><strong>Smart Separation:</strong> System automatically separates teaching cards from monitoring dropdown</li>
                            <li><strong>Example:</strong> Mr. Bismark teaches Mathematics in Basic 7 A, 8 A, 9 A (cards) + Form Master of Basic 7 A (can monitor other 9 subjects via dropdown)</li>
                        </ul>
                    </div>
                </div>
                
                <div class="feature-card" style="margin-top: 30px;">
                    <h3>🔐 Role-Based Access Control (Security)</h3>
                    <ul>
                        <li><strong>Table-Level Verification:</strong> System checks actual assignments, not just role field</li>
                        <li><strong>Form Masters Cannot Promote:</strong> Promotion buttons hidden from non-admin users</li>
                        <li><strong>View-Only Protection:</strong> When monitoring other subjects, inputs are readonly (cannot accidentally change scores)</li>
                        <li><strong>Subject Password Required:</strong> Teachers must enter correct password to access score entry</li>
                        <li><strong>School Isolation:</strong> Teachers can only see data from their assigned school</li>
                        <li><strong>Session Security:</strong> Automatic logout after inactivity</li>
                    </ul>
                </div>
            </section>
            
            <!-- Settings & Configuration -->
            <section class="section">
                <h2 class="section-title">⚙️ System Settings & Configuration</h2>
                
                <div class="feature-card">
                    <h3>School Information</h3>
                    <p><strong>Location:</strong> Settings → School Information</p>
                    <ul>
                        <li>Update school name and address</li>
                        <li>Add phone numbers and email</li>
                        <li>Set district and circuit (for BECE reports)</li>
                        <li>Upload school logo (appears on all reports)</li>
                        <li>Add school motto and other details</li>
                        <li><strong>Multi-Stream Setup:</strong> Specify if school has multiple streams (A, B, C, D)</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>🆕 Teacher Assignment Management</h3>
                    <p><strong>Location:</strong> Settings → Assign Subject Masters / Assign Form Masters</p>
                    <p><strong>Assign Subject Teachers:</strong></p>
                    <ul>
                        <li>Create new teacher accounts or assign existing teachers</li>
                        <li>Assign teachers to specific subjects and classes</li>
                        <li>Support for teaching same subject across multiple classes/streams</li>
                        <li><strong>Stream Display:</strong> When school has multiple streams, assignments clearly show which stream (e.g., "Mathematics - Basic 7 A")</li>
                        <li>View all current subject assignments in organized table</li>
                        <li>Remove assignments when teachers change</li>
                    </ul>
                    <p><strong>Assign Form Masters:</strong></p>
                    <ul>
                        <li>Assign one form master per class</li>
                        <li>System preserves subject_master role for dual-role teachers</li>
                        <li>Form master gets dashboard access for assigned class</li>
                        <li>Can monitor all subjects in form class (view-only for non-teaching subjects)</li>
                        <li><strong>Stream Support:</strong> Clearly shows which stream when assigning (Basic 7 A vs Basic 7 B)</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Academic Year & Term</h3>
                    <p><strong>How to use:</strong></p>
                    <ul>
                        <li>Set current academic year (e.g., 2024/2025)</li>
                        <li>Set current term (1, 2, or 3)</li>
                        <li>All scores automatically tagged with year and term</li>
                        <li>Export scores before changing to new term</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Password Management</h3>
                    <ul>
                        <li><strong>Admin Password:</strong> Change your login password</li>
                        <li><strong>Subject Passwords:</strong> Set/change passwords for each subject</li>
                        <li><strong>Teacher Passwords:</strong> Teachers can change their own login passwords</li>
                        <li>Keep passwords secure and share only with authorized teachers</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Prepare for New Term</h3>
                    <p><strong>Location:</strong> Settings → Prepare for New Term</p>
                    <ul>
                        <li>Export current term scores (automatic backup)</li>
                        <li>Clear all scores for fresh term</li>
                        <li>Update term number</li>
                        <li>Students remain in system (no need to re-enter)</li>
                        <li>Teacher assignments remain unchanged</li>
                    </ul>
                </div>
            </section>
            
            <!-- Data Export & Backup -->
            <section class="section">
                <h2 class="section-title">💾 Data Export & Backup</h2>
                
                <div class="feature-card">
                    <h3>Export Your Data</h3>
                    <p><strong>Location:</strong> Settings → Data Management</p>
                    <ul>
                        <li><strong>Export Students:</strong> Download all student records to Excel</li>
                        <li><strong>Export Scores:</strong> Save term scores before starting new term</li>
                        <li><strong>Download Reports:</strong> All reports can be saved as PDF</li>
                        <li><strong>Print Anything:</strong> Every page is print-friendly</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>Data Safety</h3>
                    <ul>
                        <li>All data automatically saved in real-time</li>
                        <li>Secure database storage with backup protection</li>
                        <li>Developer maintains regular system backups</li>
                        <li>Export your data anytime for your own records</li>
                        <li>Your data is never lost or deleted without permission</li>
                    </ul>
                </div>
            </section>
            
            <!-- Ghana Education System -->
            <section class="section">
                <h2 class="section-title">🎓 Ghana Education System Grading</h2>
                
                <div class="grid">
                    <div class="grid-item">
                        <h4>Grade Scale (1-9)</h4>
                        <ul style="list-style: none;">
                            <li><span class="badge-success badge">Grade 1</span> 90-100% (Highest)</li>
                            <li><span class="badge-success badge">Grade 2</span> 80-89% (Higher)</li>
                            <li><span class="badge-success badge">Grade 3</span> 70-79% (High)</li>
                            <li><span class="badge-info badge">Grade 4</span> 60-69% (High Average)</li>
                            <li><span class="badge-info badge">Grade 5</span> 55-59% (Average)</li>
                            <li><span class="badge-info badge">Grade 6</span> 50-54% (Low Average)</li>
                            <li><span class="badge-warning badge">Grade 7</span> 40-49% (Lower)</li>
                            <li><span class="badge-warning badge">Grade 8</span> 35-39% (Lowest)</li>
                            <li><span class="badge" style="background: #e74c3c;">Grade 9</span> 0-34% (Fail)</li>
                        </ul>
                    </div>
                    
                    <div class="grid-item">
                        <h4>BECE Aggregate System</h4>
                        <p><strong>Calculation:</strong></p>
                        <ul>
                            <li>4 Core Subjects (English, Math, Science, Social Studies)</li>
                            <li>2 Best Elective Subjects</li>
                            <li>Total: 6 subjects</li>
                            <li>Range: 6-48 (lower is better)</li>
                        </ul>
                        <p><strong>Interpretation:</strong></p>
                        <ul>
                            <li>6-12: Excellent</li>
                            <li>13-24: Good</li>
                            <li>25-36: Satisfactory (Pass)</li>
                            <li>37-48: Fail</li>
                        </ul>
                    </div>
                </div>
            </section>
            
            <!-- Mobile Usage -->
            <section class="section">
                <h2 class="section-title">📱 Mobile Usage Tips</h2>
                
                <div class="feature-card">
                    <h3>Optimized for Mobile Devices</h3>
                    <ul>
                        <li><strong>Full Functionality:</strong> All features work on smartphones and tablets</li>
                        <li><strong>Touch-Friendly:</strong> Large buttons for easy tapping</li>
                        <li><strong>Responsive Design:</strong> Adapts to all screen sizes</li>
                        <li><strong>Enter Scores Anywhere:</strong> Use phone to enter scores during class</li>
                        <li><strong>View Reports:</strong> Check performance on the go</li>
                    </ul>
                </div>
            </section>
            
            <!-- Pricing -->
            <div class="pricing-box">
                <h3>💰 Simple, Transparent Pricing</h3>
                <div class="price">Pay Once, Use Forever!</div>
                <ul>
                    <li>No monthly subscription fees</li>
                    <li>3-day FREE trial after approval</li>
                    <li>Unlimited students and teachers</li>
                    <li>All features included</li>
                    <li>Free updates and improvements</li>
                    <li>Mobile access at no extra cost</li>
                    <li>Database optimization tools included</li>
                    <li>Lifetime access after one-time payment</li>
                </ul>
            </div>
            
            <!-- Contact Information -->
            <div class="contact-box">
                <h3>📞 Need Help? Contact Developer</h3>
                <p>For registration, payment, support, or questions:</p>
                <div class="phone">📱 0257514418</div>
                <div class="phone">📱 0502160502</div>
                <p style="margin-top: 15px;">Available for technical support, training, and system queries</p>
                <p style="margin-top: 10px; font-size: 0.9rem; opacity: 0.9;">WhatsApp available on both numbers</p>
            </div>
            
            <!-- Developer Controls -->
            <section class="section">
                <h2 class="section-title">🔧 Developer Features (Admin-Only)</h2>
                
                <div class="feature-card">
                    <h3>🔒 School Lock/Unlock System</h3>
                    <p><strong>What it is:</strong> Developer can temporarily lock schools to prevent access by all users from that school.</p>
                    <p><strong>When it's used:</strong></p>
                    <ul>
                        <li>Payment overdue or subscription lapsed</li>
                        <li>Policy violations or terms of service breach</li>
                        <li>System maintenance required</li>
                        <li>Emergency security measures</li>
                        <li>Temporary service suspension</li>
                    </ul>
                    <p><strong>What happens when locked:</strong></p>
                    <ul>
                        <li>All users from that school cannot login</li>
                        <li>Already logged-in users are automatically logged out</li>
                        <li>Custom reason message is displayed on login page</li>
                        <li>Developer contact information shown for resolution</li>
                        <li>School data remains safe and intact</li>
                    </ul>
                    <p><strong>How to unlock:</strong></p>
                    <ul>
                        <li>Contact developer at phone numbers above</li>
                        <li>Resolve the issue (payment, policy compliance, etc.)</li>
                        <li>Developer unlocks the school</li>
                        <li>Immediate access restored for all users</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>👨‍💻 Developer Dashboard</h3>
                    <p><strong>Exclusive Access:</strong> Only developer has access to:</p>
                    <ul>
                        <li>View all registered schools</li>
                        <li>Approve/reject new school registrations</li>
                        <li>Manage trial periods and extend trials</li>
                        <li>Mark schools as paid (lifetime access)</li>
                        <li><strong>NEW:</strong> Lock/unlock schools with custom reasons</li>
                        <li>Reset school admin passwords</li>
                        <li>Delete schools and all related data</li>
                        <li>View system statistics and analytics</li>
                        <li>Monitor system health and security</li>
                    </ul>
                </div>
            </section>
            
            <!-- Why Choose Us -->
            <section class="section">
                <h2 class="section-title">🏆 Why Schools Choose Our System (Version 2.0)</h2>
                
                <div class="grid">
                    <div class="grid-item">
                        <h4>✅ Cost Effective</h4>
                        <p>One-time payment instead of expensive monthly subscriptions. Save thousands over time!</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>✅ Ghana-Specific</h4>
                        <p>Built for Ghana Education System with BECE grading, mock analysis, and GES-compliant reports.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>✅ Easy to Use</h4>
                        <p>Intuitive interface requires minimal training. Teachers can start using immediately!</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>✅ Mobile Ready</h4>
                        <p>Works perfectly on phones and tablets. Enter scores during class without returning to office.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>✅ Complete Security</h4>
                        <p>Your school data is 100% isolated. No other school can see or access your information.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>✅ Professional Reports</h4>
                        <p>Impress parents and GES with beautifully formatted reports, broadsheets, and analysis.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🆕 Multi-Stream Support</h4>
                        <p>Perfect for schools with parallel classes (A, B, C, D). Stream labels automatically show only when needed.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🆕 Dual-Role Teachers</h4>
                        <p>Teachers who both teach subjects AND manage a form class get specialized interface with cards + monitoring dashboard.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🆕 Form Master Dashboard</h4>
                        <p>Form masters can monitor score entry progress across all subjects, view class reports, and generate student reports—all without entering scores.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🆕 Smart Navigation</h4>
                        <p>Card-based interface for teaching, dropdown for monitoring. Teachers see exactly what they need, nothing more.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🆕 Enhanced UI/UX</h4>
                        <p>Smooth scrolling, position memory, view-only mode indicators, and responsive design for the best user experience.</p>
                    </div>
                    
                    <div class="grid-item">
                        <h4>🆕 Production Ready</h4>
                        <p>Version 2.0 is battle-tested and ready for schools of any size with comprehensive documentation and deployment guides.</p>
                    </div>
                </div>
            </section>
            
            <!-- New Features Highlight -->
            <section class="section">
                <h2 class="section-title">🎉 What's New in Version 2.0</h2>
                
                <div class="feature-card">
                    <h3>🔥 Major Enhancements</h3>
                    <div class="grid" style="margin-top: 15px;">
                        <div style="padding: 15px; background: #e6f7ff; border-left: 4px solid #1890ff; margin-bottom: 10px;">
                            <h4 style="color: #1890ff; margin-bottom: 8px;">Multi-Stream Architecture</h4>
                            <p>Full support for schools with multiple streams (A, B, C, D). System automatically detects and displays stream information only when your school has parallel classes.</p>
                        </div>
                        
                        <div style="padding: 15px; background: #f0f9ff; border-left: 4px solid #0284c7; margin-bottom: 10px;">
                            <h4 style="color: #0284c7; margin-bottom: 8px;">Dual-Role Teacher System</h4>
                            <p>Revolutionary interface for teachers who both teach subjects AND manage a form class. Separate cards for teaching + dropdown for monitoring other subjects.</p>
                        </div>
                        
                        <div style="padding: 15px; background: #f0fdf4; border-left: 4px solid #16a34a; margin-bottom: 10px;">
                            <h4 style="color: #16a34a; margin-bottom: 8px;">Form Master Monitoring</h4>
                            <p>Form masters can check score entry progress for ALL subjects in their form class. View-only mode prevents accidental changes to subjects they don't teach.</p>
                        </div>
                        
                        <div style="padding: 15px; background: #fefce8; border-left: 4px solid #ca8a04; margin-bottom: 10px;">
                            <h4 style="color: #ca8a04; margin-bottom: 8px;">Smart UI Enhancements</h4>
                            <p>Smooth scrolling, scroll position memory, visual feedback for view-only mode, and mobile-optimized touch interactions.</p>
                        </div>
                        
                        <div style="padding: 15px; background: #faf5ff; border-left: 4px solid #9333ea; margin-bottom: 10px;">
                            <h4 style="color: #9333ea; margin-bottom: 8px;">Enhanced Security</h4>
                            <p>Role-based access control with table-level verification, readonly inputs for monitoring mode, and session management improvements.</p>
                        </div>
                        
                        <div style="padding: 15px; background: #fff1f2; border-left: 4px solid #e11d48; margin-bottom: 10px;">
                            <h4 style="color: #e11d48; margin-bottom: 8px;">Access Control Refinements</h4>
                            <p>Form masters restricted from promotion features, proper role preservation when assigning teachers, and improved permission checks throughout system.</p>
                        </div>
                    </div>
                </div>
                
                <div class="feature-card">
                    <h3>📋 Real-World Use Cases</h3>
                    <ul>
                        <li><strong>Scenario 1:</strong> Mr. Bismark teaches Mathematics in Basic 7 A, Basic 8 A, and Basic 9 A. He's also the form master of Basic 7 A. When he logs in:
                            <ul>
                                <li>Sees 3 teaching cards (Mathematics for each class)</li>
                                <li>Clicks card to enter scores with full edit access</li>
                                <li>Sees dropdown with 9 other subjects in Basic 7 A for monitoring</li>
                                <li>Selects "English" from dropdown → View-only mode (can check if scores entered)</li>
                                <li>Can access dashboard, broadsheet, and reports for Basic 7 A</li>
                            </ul>
                        </li>
                        <li><strong>Scenario 2:</strong> School has Basic 7 A and Basic 7 B (two streams). When assigning teachers:
                            <ul>
                                <li>Dropdowns clearly show "Basic 7 - Stream A" and "Basic 7 - Stream B"</li>
                                <li>Assignment table shows which stream each teacher is assigned to</li>
                                <li>Teachers see stream labels throughout score entry interface</li>
                            </ul>
                        </li>
                        <li><strong>Scenario 3:</strong> Form master wants to check if all teachers have completed score entry before term ends:
                            <ul>
                                <li>Goes to Score Entry page</li>
                                <li>Uses monitoring dropdown to select each subject</li>
                                <li>Green banner shows "You are viewing this subject in monitoring mode"</li>
                                <li>Can see which students have scores and which are missing</li>
                                <li>Follows up with specific teachers who haven't completed entry</li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </section>
            
            <!-- Tips & Best Practices -->
            <section class="section">
                <h2 class="section-title">💡 Tips & Best Practices (Updated for v2.0)</h2>
                
                <div class="feature-card">
                    <h3>For Administrators</h3>
                    <ul>
                        <li>Update school information immediately after first login</li>
                        <li>Upload high-quality school logo for professional reports</li>
                        <li><strong>NEW:</strong> Specify if your school has multiple streams in settings</li>
                        <li><strong>NEW:</strong> Use "Assign Subject Masters" page to create teacher accounts and assign to subjects</li>
                        <li><strong>NEW:</strong> Use "Assign Form Masters" page to assign class teachers (system preserves subject_master role)</li>
                        <li>Assign form masters to their respective classes</li>
                        <li>Set unique subject passwords and share only with authorized teachers</li>
                        <li>Backup database regularly (at least monthly)</li>
                        <li>Export scores before starting new term</li>
                        <li>Add district/circuit info for BECE mock analysis</li>
                        <li><strong>Security:</strong> Only admins can promote students—form masters cannot</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>For Subject Teachers</h3>
                    <ul>
                        <li>Enter scores progressively throughout the term, not all at once</li>
                        <li>Use "All Students" view for faster bulk score entry</li>
                        <li>Add meaningful remarks to help students improve</li>
                        <li>Double-check scores before submitting</li>
                        <li>Use mobile phone to enter scores during class for efficiency</li>
                        <li>Generate reports regularly to track student progress</li>
                        <li><strong>NEW:</strong> If you teach multiple classes, use the card interface to quickly switch between them</li>
                        <li><strong>NEW:</strong> Page remembers scroll position—no need to scroll back after saving</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>For Form Masters/Class Teachers</h3>
                    <ul>
                        <li><strong>NEW:</strong> Use monitoring dropdown to check score entry progress across all subjects</li>
                        <li><strong>NEW:</strong> Green "Monitoring Mode" banner indicates view-only access</li>
                        <li><strong>NEW:</strong> Cannot edit scores for subjects you don't teach (inputs are readonly)</li>
                        <li>Access dashboard to view class performance overview</li>
                        <li>Generate broadsheet regularly to identify struggling students</li>
                        <li>Use result analysis to prepare for parent meetings</li>
                        <li>Generate student reports at end of term</li>
                        <li>Follow up with subject teachers who haven't completed score entry</li>
                        <li><strong>Remember:</strong> You cannot promote students—contact admin for promotions</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>For Dual-Role Teachers (Teach + Form Master)</h3>
                    <ul>
                        <li><strong>NEW:</strong> You'll see both teaching cards AND monitoring dropdown on score entry page</li>
                        <li><strong>Teaching Cards:</strong> Click any card to enter scores for subjects you teach (full edit access)</li>
                        <li><strong>Monitoring Dropdown:</strong> Select subjects you DON'T teach to check progress (view-only)</li>
                        <li>Use cards for daily score entry work</li>
                        <li>Use dropdown for monitoring and quality control</li>
                        <li>Access full dashboard for your form class</li>
                        <li>Take advantage of both roles to maintain high academic standards</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>For Multi-Stream Schools</h3>
                    <ul>
                        <li><strong>NEW:</strong> Stream labels (A, B, C, D) automatically appear throughout the system</li>
                        <li>When assigning teachers, carefully select the correct stream</li>
                        <li>Verify stream information when entering scores</li>
                        <li>Reports and broadsheets show stream clearly to avoid confusion</li>
                        <li><strong>Single-Stream Schools:</strong> Stream features are hidden—system detects automatically</li>
                    </ul>
                </div>
                
                <div class="feature-card">
                    <h3>For BECE Preparation</h3>
                    <ul>
                        <li>Ensure all Basic 9 students have candidate index numbers</li>
                        <li>Enable Mock Exam Mode in Settings</li>
                        <li>Conduct regular mock exams throughout the year</li>
                        <li>Use Mock Analysis to identify weak subjects</li>
                        <li>Share analysis reports with teachers for targeted intervention</li>
                        <li>Compare performance across terms to track improvement</li>
                    </ul>
                </div>
            </section>
            
            <!-- Common Questions -->
            <section class="section">
                <h2 class="section-title">❓ Common Questions</h2>
                
                <div class="feature-card">
                    <h3>Q: How many students can I add?</h3>
                    <p><strong>A:</strong> Unlimited! There's no limit on the number of students, teachers, or classes you can have.</p>
                </div>
                
                <div class="feature-card">
                    <h3>Q: Can I use this on my phone?</h3>
                    <p><strong>A:</strong> Yes! The system is fully optimized for mobile devices. You can do everything on your smartphone or tablet.</p>
                </div>
                
                <div class="feature-card">
                    <h3>Q: What happens to my data if I don't pay after trial?</h3>
                    <p><strong>A:</strong> Your data is safe! It remains in the system. Once you pay, you'll regain immediate access to everything.</p>
                </div>
                
                <div class="feature-card">
                    <h3>Q: Can other schools see my data?</h3>
                    <p><strong>A:</strong> No! Each school's data is completely isolated. It's impossible for other schools to access your information.</p>
                </div>
                
                <div class="feature-card">
                    <h3>Q: Do I need internet all the time?</h3>
                    <p><strong>A:</strong> You need internet to access the system. However, reports can be downloaded as PDF for offline viewing and printing.</p>
                </div>
                
                <div class="feature-card">
                    <h3>Q: Can I export my data?</h3>
                    <p><strong>A:</strong> Yes! You can export student lists to Excel, backup entire database, and download all reports as PDF files.</p>
                </div>
                
                <div class="feature-card">
                    <h3>Q: Is training provided?</h3>
                    <p><strong>A:</strong> The system is very intuitive and easy to learn. However, developer can provide training if needed (contact numbers above).</p>
                </div>
            </section>
            
            <!-- Final CTA -->
            <div class="highlight-box">
                <h3>🚀 Ready to Transform Your School Management?</h3>
                <p><strong>Version 2.0 is Here!</strong> With multi-stream support, dual-role teacher interface, form master monitoring, and enhanced UI/UX.</p>
                <p style="margin-top: 15px;">Register your school today and enjoy <strong>3 days of FREE trial</strong> with full access to all features!</p>
                <p style="margin-top: 20px;">
                    <a href="school_register.php" style="display: inline-block; padding: 15px 40px; background: white; color: #667eea; text-decoration: none; border-radius: 10px; font-weight: bold; font-size: 1.2rem; margin: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">📝 Register Now (FREE Trial)</a>
                    <a href="login.php" style="display: inline-block; padding: 15px 40px; background: rgba(255,255,255,0.2); color: white; text-decoration: none; border-radius: 10px; font-weight: bold; font-size: 1.2rem; margin: 10px; border: 2px solid white;">← Back to Login</a>
                </p>
            </div>
            
            <div style="text-align: center; padding: 30px; color: #718096;">
                <p style="font-size: 1.1rem;"><strong>Powered by TechLaw Softwares</strong></p>
                <p><strong>Version 2.0</strong> | Released November 2025</p>
                <p style="margin-top: 10px;">📱 Developer Contact: 0257514418 / 0502160502</p>
                <p>© <?php echo date('Y'); ?> All Rights Reserved</p>
            </div>
        </div>
    </div>
</body>
</html>
