<template>
    <v-app>
        <!-- 🔐 登入/註冊頁面 - 不顯示主佈局 -->
        <template v-if="hideLayout">
            <router-view></router-view>
        </template>

        <!-- 📊 主要應用佈局 - 顯示側邊欄和導航欄 -->
        <template v-else>
            <!-- 側邊欄 -->
            <v-navigation-drawer v-model="drawer" :rail="rail" permanent @click="rail = false">
                <v-list-item v-if="!rail" prepend-avatar="/logo.png" title="Stock_Analysis" subtitle="台股選擇權交易系統" nav>
                    <template v-slot:append>
                        <v-btn variant="text" icon="mdi-chevron-left" @click.stop="rail = !rail"></v-btn>
                    </template>
                </v-list-item>

                <v-list-item v-else nav class="text-center">
                    <template v-slot:prepend>
                        <v-btn variant="text" icon="mdi-menu" @click.stop="rail = !rail"></v-btn>
                    </template>
                </v-list-item>

                <v-divider></v-divider>

                <v-list density="compact" nav>
                    <v-list-item v-for="item in menuItems" :key="item.title" :prepend-icon="item.icon"
                        :title="item.title" :to="item.to" :value="item.title" color="primary"
                        v-show="!item.adminOnly || isAdmin"></v-list-item>
                </v-list>
            </v-navigation-drawer>

            <!-- 頂部導航欄 -->
            <v-app-bar>
                <v-app-bar-nav-icon @click="drawer = !drawer" v-if="mobile"></v-app-bar-nav-icon>

                <v-toolbar-title>
                    {{ currentPageTitle }}
                </v-toolbar-title>

                <v-spacer></v-spacer>

                <v-chip class="mr-2" v-if="user">
                    <v-icon start>mdi-account</v-icon>
                    {{ user.name }}
                </v-chip>

                <v-chip class="mr-2" :color="marketStatus.color">
                    <v-icon start>{{ marketStatus.icon }}</v-icon>
                    {{ marketStatus.text }}
                </v-chip>

                <v-chip class="mr-2">
                    <v-icon start>mdi-clock</v-icon>
                    {{ currentTime }}
                </v-chip>

                <v-btn icon @click="toggleTheme">
                    <v-icon>{{ theme.global.current.value.dark ? 'mdi-weather-sunny' : 'mdi-weather-night' }}</v-icon>
                </v-btn>

                <v-btn icon @click="logout">
                    <v-icon>mdi-logout</v-icon>
                </v-btn>
            </v-app-bar>

            <!-- 主要內容區 -->
            <v-main>
                <v-container fluid>
                    <router-view></router-view>
                </v-container>
            </v-main>
        </template>

        <!-- 登出確認對話框 -->
        <v-dialog v-model="showLogoutDialog" max-width="400">
            <v-card>
                <v-card-title>確認登出</v-card-title>
                <v-card-text>確定要登出系統嗎？</v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn color="grey" @click="showLogoutDialog = false">取消</v-btn>
                    <v-btn color="primary" @click="confirmLogout" :loading="loggingOut">確定登出</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- 全域載入提示 -->
        <v-snackbar v-model="showSnackbar" :color="snackbarColor" :timeout="3000" top>
            {{ snackbarText }}
            <template v-slot:actions>
                <v-btn variant="text" @click="showSnackbar = false">關閉</v-btn>
            </template>
        </v-snackbar>
    </v-app>
</template>

<script>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useTheme } from 'vuetify'
import { useDisplay } from 'vuetify'
import axios from 'axios'

export default {
    name: 'App',
    setup() {
        const router = useRouter()
        const route = useRoute()
        const theme = useTheme()
        const { mobile } = useDisplay()

        const drawer = ref(true)
        const rail = ref(false)
        const notifications = ref(0)
        const currentTime = ref('')
        const showLogoutDialog = ref(false)
        const loggingOut = ref(false)
        const showSnackbar = ref(false)
        const snackbarText = ref('')
        const snackbarColor = ref('success')
        let timeInterval = null

        const user = ref(null)

        // ==========================================
        // 選單項目 (僅保留需求範圍內的頁面)
        // ==========================================
        const menuItems = ref([
            { title: '儀表板', icon: 'mdi-view-dashboard', to: '/dashboard' },
            { title: '股票報價', icon: 'mdi-chart-line', to: '/stocks' },
            { title: '選擇權鏈', icon: 'mdi-format-list-bulleted', to: '/options' },
            { title: 'Black-Scholes', icon: 'mdi-calculator', to: '/black-scholes' },
            { title: '波動率分析', icon: 'mdi-chart-bell-curve', to: '/volatility' },
            { title: '預測模型', icon: 'mdi-crystal-ball', to: '/predictions' },
            { title: '策略回測', icon: 'mdi-history', to: '/backtest' },
            { title: '系統管理', icon: 'mdi-cog', to: '/admin', adminOnly: true },  // ← 新增
        ])

        const isAdmin = computed(() => {
            return user.value?.email === 'admin@stock.com'
        })

        const hideLayout = computed(() => route.meta.hideLayout === true)

        const currentPageTitle = computed(() => {
            const currentRoute = menuItems.value.find(item => item.to === route.path)
            return currentRoute ? currentRoute.title : 'Stock_Analysis'
        })

        const marketStatus = computed(() => {
            const now = new Date()
            const hour = now.getHours()
            const minute = now.getMinutes()
            const day = now.getDay()

            if (day === 0 || day === 6) {
                return { text: '休市', color: 'grey', icon: 'mdi-sleep' }
            }
            if ((hour === 9 && minute >= 0) || (hour >= 10 && hour < 13) || (hour === 13 && minute <= 30)) {
                return { text: '開盤中', color: 'success', icon: 'mdi-chart-line-variant' }
            }
            if ((hour === 13 && minute > 30) || hour === 14) {
                return { text: '盤後交易', color: 'warning', icon: 'mdi-clock-alert' }
            }
            return { text: '已收盤', color: 'grey', icon: 'mdi-close-circle' }
        })

        const updateTime = () => {
            const now = new Date()
            currentTime.value = now.toLocaleTimeString('zh-TW', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            })
        }

        const loadUser = () => {
            const userStr = localStorage.getItem('user')
            if (userStr) {
                try {
                    user.value = JSON.parse(userStr)
                } catch (e) {
                    console.error('解析使用者資訊失敗:', e)
                }
            }
        }

        const toggleTheme = () => {
            theme.global.name.value = theme.global.current.value.dark ? 'light' : 'dark'
        }

        const logout = () => {
            showLogoutDialog.value = true
        }

        const confirmLogout = async () => {
            loggingOut.value = true
            try {
                await axios.post('auth/logout')
                localStorage.removeItem('authToken')
                localStorage.removeItem('user')
                delete axios.defaults.headers.common['Authorization']
                showLogoutDialog.value = false
                snackbarText.value = '已成功登出'
                snackbarColor.value = 'success'
                showSnackbar.value = true
                setTimeout(() => { router.push('/login') }, 500)
            } catch (error) {
                localStorage.removeItem('authToken')
                localStorage.removeItem('user')
                delete axios.defaults.headers.common['Authorization']
                showLogoutDialog.value = false
                snackbarText.value = '登出時發生錯誤，但已清除本地資料'
                snackbarColor.value = 'warning'
                showSnackbar.value = true
                setTimeout(() => { router.push('/login') }, 500)
            } finally {
                loggingOut.value = false
            }
        }

        onMounted(() => {
            updateTime()
            loadUser()
            timeInterval = setInterval(updateTime, 1000)
        })

        onUnmounted(() => {
            if (timeInterval) clearInterval(timeInterval)
        })

        watch(() => route.path, () => { loadUser() })

        return {
            drawer, rail, notifications, menuItems, hideLayout,
            currentPageTitle, currentTime, marketStatus, theme, mobile,
            user, showLogoutDialog, loggingOut, showSnackbar, snackbarText, snackbarColor,
            toggleTheme, logout, confirmLogout,
            isAdmin
        }
    }
}
</script>

<style>
html {
    overflow-y: auto !important;
}

.v-application {
    font-family: 'Noto Sans TC', sans-serif !important;
}

[v-cloak] {
    display: none;
}
</style>
