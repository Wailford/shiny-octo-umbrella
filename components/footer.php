    <footer style="background: #2d3748; color: white; padding: 2rem 1rem; margin-top: 3rem;">
        <div style="max-width: 1400px; margin: 0 auto; text-align: center;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div style="flex: 1; min-width: 200px; text-align: left;">
                                    <div style="text-align: center;">
                    <h3 style="margin-bottom: 0.5rem; font-size: 1.1rem;"><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Based Assessment System'); ?></h3>
                    <p style="margin: 0; font-size: 0.9rem; opacity: 0.8;"><?php echo htmlspecialchars($schoolInfo['location'] ?? ''); ?></p>
                    <?php if (!empty($schoolInfo['phone'])): ?>
                        <p style="margin: 0.25rem 0; font-size: 0.85rem; opacity: 0.8;">📞 <?php echo htmlspecialchars($schoolInfo['phone']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($schoolInfo['email'])): ?>
                        <p style="margin: 0.25rem 0; font-size: 0.85rem; opacity: 0.8;">✉ <?php echo htmlspecialchars($schoolInfo['email']); ?></p>
                    <?php endif; ?>
                </div>
                <div style="flex: 1; min-width: 200px; text-align: right;">
                    <p style="margin: 0; font-size: 0.95rem; font-weight: 600;">Powered by TechLaw Softwares</p>
                    <p style="margin: 0.5rem 0 0 0; font-size: 0.85rem; opacity: 0.8;">© <?php echo date('Y'); ?> All rights reserved</p>
                </div>
            </div>
            <div style="border-top: 1px solid rgba(255,255,255,0.1); margin-top: 1.5rem; padding-top: 1rem;">
                <p style="margin: 0; font-size: 0.8rem; opacity: 0.7;">Academic Year: <?php echo htmlspecialchars($schoolInfo['academic_year'] ?? date('Y')); ?> | Term: <?php echo htmlspecialchars($schoolInfo['current_term'] ?? 'N/A'); ?></p>
            </div>
        </div>
    </footer>
    
    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
            
            // Touch-friendly table scrolling indicator
            const tableContainers = document.querySelectorAll('.table-responsive');
            tableContainers.forEach(container => {
                if (container.scrollWidth > container.clientWidth) {
                    container.style.position = 'relative';
                    const indicator = document.createElement('div');
                    indicator.style.cssText = 'position:absolute;right:0;top:0;bottom:0;width:30px;background:linear-gradient(90deg,transparent,rgba(0,0,0,0.1));pointer-events:none;';
                    container.appendChild(indicator);
                    
                    container.addEventListener('scroll', function() {
                        if (container.scrollLeft + container.clientWidth >= container.scrollWidth - 10) {
                            indicator.style.display = 'none';
                        } else {
                            indicator.style.display = 'block';
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
