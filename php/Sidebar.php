<?php

class Sidebar
{
    private $currentPage;
    private $isMaster;

    public function __construct($currentPage = '', $isMaster = false)
    {
        $this->currentPage = $currentPage;
        $this->isMaster = (bool)$isMaster;
    }

    public function render()
    {
        ?>
        <aside class="sidebar">
            <ul class="sidebar-menu">
            
                <li class="menu-item <?= ($this->currentPage === 'home_suporte') ? 'active' : '' ?>">
                    <a href="home_suporte.php" class="menu-link">
                        <span class="menu-link-content">
                            <i class="fa-solid fa-house"></i>
                            <span>Home</span>
                        </span>
                    </a>
                </li>
                
                <li class="menu-item <?= ($this->currentPage === 'clientes') ? 'active' : '' ?>">
                    <a href="clientes.php" class="menu-link">
                        <span class="menu-link-content">
                            <i class="fa-solid fa-users"></i>
                            <span>Clientes</span>
                        </span>
                    </a>
                </li>

                <li class="menu-item <?= ($this->currentPage === 'usuarios') ? 'active' : '' ?>">
                    <a href="usuarios.php" class="menu-link">
                        <span class="menu-link-content">
                            <i class="fa-solid fa-user-gear"></i>
                            <span>Usuários</span>
                        </span>
                    </a>
                </li>

                <li class="menu-item <?= ($this->currentPage === 'chamados') ? 'active' : '' ?>">
                    <a href="chamados.php" class="menu-link">
                        <span class="menu-link-content">
                            <i class="fa-solid fa-headset"></i>
                            <span>Chamados</span>
                        </span>
                    </a>
                </li>
                
                <li class="menu-item <?= ($this->currentPage === 'relatorio_chamados') ? 'active' : '' ?>">
                    <a href="relatorio_chamados.php" class="menu-link">
                        <span class="menu-link-content">
                            <i class="fa-regular fa-file-lines"></i>
                            <span>Relatório de Chamados</span>
                        </span>
                    </a>
                </li>
                
                <li class="menu-item <?= ($this->currentPage === 'agendamentos') ? 'active' : '' ?>">
                    <a href="agendamentos.php" class="menu-link">
                        <span class="menu-link-content">
                            <i class="fa-solid fa-calendar"></i>
                            <span>Agendamentos</span>
                        </span>
                    </a>
                </li>

                <!-- Exibe Financeiro apenas para usuários MASTER -->
                <?php if ($this->isMaster): ?>
                    <?php 
                        $isFinanceiroActive = in_array($this->currentPage, [
                            'contas_pagar_receber', 'caixas_bancarios', 'fluxo_caixa', 'relatorios_fin', 'resultado'
                        ]);
                    ?>
                    <li class="menu-item has-submenu <?= $isFinanceiroActive ? 'open' : '' ?>">
                        <div class="menu-link js-toggle-submenu">
                            <span class="menu-link-content">
                                <i class="fa-solid fa-wallet"></i>
                                <span>Financeiro</span>
                            </span>
                            <i class="fa-solid fa-chevron-down arrow-icon"></i>
                        </div>
                        <ul class="submenu">
                            <li class="menu-item <?= ($this->currentPage === 'contas_pagar_receber') ? 'active' : '' ?>">
                                <a href="financeiro_contas.php" class="menu-link">
                                    <span class="menu-link-content">
                                        <i class="fa-solid fa-file-invoice-dollar"></i>
                                        <span>Contas a Pagar e Receber</span>
                                    </span>
                                </a>
                            </li>
                            <li class="menu-item <?= ($this->currentPage === 'caixas_bancarios') ? 'active' : '' ?>">
                                <a href="financeiro_caixas.php" class="menu-link">
                                    <span class="menu-link-content">
                                        <i class="fa-solid fa-building-columns"></i>
                                        <span>Caixas Bancários</span>
                                    </span>
                                </a>
                            </li>
                            <li class="menu-item <?= ($this->currentPage === 'fluxo_caixa') ? 'active' : '' ?>">
                                <a href="financeiro_fluxo.php" class="menu-link">
                                    <span class="menu-link-content">
                                        <i class="fa-solid fa-chart-line"></i>
                                        <span>Fluxo de Caixa</span>
                                    </span>
                                </a>
                            </li>
                            <li class="menu-item <?= ($this->currentPage === 'relatorios_fin') ? 'active' : '' ?>">
                                <a href="financeiro_relatorios.php" class="menu-link">
                                    <span class="menu-link-content">
                                        <i class="fa-solid fa-file-lines"></i>
                                        <span>Relatórios</span>
                                    </span>
                                </a>
                            </li>
                            <li class="menu-item <?= ($this->currentPage === 'resultado') ? 'active' : '' ?>">
                                <a href="financeiro_resultado.php" class="menu-link">
                                    <span class="menu-link-content">
                                        <i class="fa-solid fa-chart-pie"></i>
                                        <span>Resultado</span>
                                    </span>
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>

            </ul>
        </aside>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const toggles = document.querySelectorAll('.js-toggle-submenu');
                toggles.forEach(toggle => {
                    toggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        this.parentElement.classList.toggle('open');
                    });
                });
            });
        </script>
        <?php
    }
}