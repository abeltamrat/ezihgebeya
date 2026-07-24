import { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { AppNotFound } from './pages/AppNotFound';

const AccountSettings = lazy(() => import('./pages/AccountSettings').then((m) => ({ default: m.AccountSettings })));
const AccountFavorites = lazy(() => import('./pages/AccountFavorites').then((m) => ({ default: m.AccountFavorites })));
const AccountInquiries = lazy(() => import('./pages/AccountInquiries').then((m) => ({ default: m.AccountInquiries })));
const AccountInquiryThread = lazy(() => import('./pages/AccountInquiryThread').then((m) => ({ default: m.AccountInquiryThread })));
const AccountNotifications = lazy(() => import('./pages/AccountNotifications').then((m) => ({ default: m.AccountNotifications })));
const AccountReviewsReports = lazy(() => import('./pages/AccountReviewsReports').then((m) => ({ default: m.AccountReviewsReports })));
const AccountOrders = lazy(() => import('./pages/AccountOrders').then((m) => ({ default: m.AccountOrders })));
const Cart = lazy(() => import('./pages/Cart').then((m) => ({ default: m.Cart })));
const Checkout = lazy(() => import('./pages/Checkout').then((m) => ({ default: m.Checkout })));
const VendorDashboard = lazy(() => import('./pages/VendorDashboard').then((m) => ({ default: m.VendorDashboard })));
const VendorBusinessProfile = lazy(() => import('./pages/VendorBusinessProfile').then((m) => ({ default: m.VendorBusinessProfile })));
const VendorInquiries = lazy(() => import('./pages/VendorInquiries').then((m) => ({ default: m.VendorInquiries })));
const VendorInquiryThread = lazy(() => import('./pages/VendorInquiryThread').then((m) => ({ default: m.VendorInquiryThread })));
const VendorListings = lazy(() => import('./pages/VendorListings').then((m) => ({ default: m.VendorListings })));
const VendorListingForm = lazy(() => import('./pages/VendorListingForm').then((m) => ({ default: m.VendorListingForm })));
const VendorOrders = lazy(() => import('./pages/VendorOrders').then((m) => ({ default: m.VendorOrders })));
const VendorVideos = lazy(() => import('./pages/VendorVideos').then((m) => ({ default: m.VendorVideos })));
const VendorVerification = lazy(() => import('./pages/VendorVerification').then((m) => ({ default: m.VendorVerification })));
const VendorReviews = lazy(() => import('./pages/VendorReviews').then((m) => ({ default: m.VendorReviews })));
const VendorAnalytics = lazy(() => import('./pages/VendorAnalytics').then((m) => ({ default: m.VendorAnalytics })));
const VendorBoost = lazy(() => import('./pages/VendorBoost').then((m) => ({ default: m.VendorBoost })));
const VendorSoftware = lazy(() => import('./pages/VendorSoftware').then((m) => ({ default: m.VendorSoftware })));
const AdminHealth = lazy(() => import('./pages/AdminHealth').then((m) => ({ default: m.AdminHealth })));
const AdminMonetization = lazy(() => import('./pages/AdminMonetization').then((m) => ({ default: m.AdminMonetization })));

const VENDOR_ROLES = ['seller', 'manufacturer', 'importer', 'service_provider', 'supplier'];
const ADMIN_ROLES = ['admin', 'super_admin'];

export default function App() {
  return (
    <Suspense fallback={<div className="container section"><p className="muted">Loading screen…</p></div>}>
    <Routes>
      <Route
        path="/account"
        element={
          <ProtectedRoute>
            <AccountSettings />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/notifications"
        element={
          <ProtectedRoute>
            <AccountNotifications />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/inquiries"
        element={
          <ProtectedRoute>
            <AccountInquiries />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/inquiries/:id"
        element={
          <ProtectedRoute>
            <AccountInquiryThread />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/favorites"
        element={
          <ProtectedRoute>
            <AccountFavorites />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/settings"
        element={
          <ProtectedRoute>
            <AccountSettings />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/reviews"
        element={
          <ProtectedRoute>
            <AccountReviewsReports />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/reports"
        element={
          <ProtectedRoute>
            <AccountReviewsReports />
          </ProtectedRoute>
        }
      />
      <Route
        path="/account/orders"
        element={
          <ProtectedRoute>
            <AccountOrders />
          </ProtectedRoute>
        }
      />
      <Route
        path="/cart"
        element={
          <ProtectedRoute>
            <Cart />
          </ProtectedRoute>
        }
      />
      <Route
        path="/checkout"
        element={
          <ProtectedRoute>
            <Checkout />
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/health"
        element={
          <ProtectedRoute roles={ADMIN_ROLES}>
            <AdminHealth />
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/monetization"
        element={
          <ProtectedRoute roles={ADMIN_ROLES}>
            <AdminMonetization />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorDashboard />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/business"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorBusinessProfile />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/inquiries"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorInquiries />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/inquiries/:id"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorInquiryThread />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/analytics"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorAnalytics />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/reviews"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorReviews />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/verification"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorVerification />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/videos"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorVideos />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/orders"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorOrders />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/boost"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorBoost />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/software"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorSoftware />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/listings/:type"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorListings />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/listings/:type/new"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorListingForm />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/listings/:type/:id/edit"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorListingForm />
          </ProtectedRoute>
        }
      />
      <Route path="*" element={<AppNotFound />} />
    </Routes>
    </Suspense>
  );
}
