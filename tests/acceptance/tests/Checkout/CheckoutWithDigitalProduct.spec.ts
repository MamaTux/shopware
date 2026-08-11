import { test, expect, getOrderTransactionId } from '@fixtures/AcceptanceTest';

test(
    'Registered shop customer should be able to buy a digital product.',
    {
        tag: [
            '@Checkout',
            '@DigitalProduct',
            '@Storefront',
        ],
    },
    async ({
        ShopCustomer,
        AdminApiContext,
        TestDataService,
        StorefrontProductDetail,
        StorefrontCheckoutFinish,
        StorefrontAccountOrder,
        Login,
        ProceedFromProductToCheckout,
        ConfirmTermsAndConditions,
        ConfirmImmediateAccessToDigitalProduct,
        SelectPaymentMethod,
        SubmitOrder,
        DownloadDigitalProductFromOrderAndExpectContentToBe,
    }) => {
        const fileContent = 'This is a test.';
        const digitalProduct = await TestDataService.createDigitalProduct(fileContent);

        await ShopCustomer.attemptsTo(Login());

        await ShopCustomer.goesTo(StorefrontProductDetail.url(digitalProduct));

        await ShopCustomer.presses(StorefrontProductDetail.addToCartButton);
        await ShopCustomer.expects(StorefrontProductDetail.offCanvasCartTitle).toBeVisible();
        await ShopCustomer.expects(StorefrontProductDetail.offCanvasCart.getByText(digitalProduct.name)).toBeVisible();
        await ShopCustomer.attemptsTo(ProceedFromProductToCheckout());

        await ShopCustomer.attemptsTo(ConfirmTermsAndConditions());
        await ShopCustomer.attemptsTo(ConfirmImmediateAccessToDigitalProduct());
        await ShopCustomer.attemptsTo(SelectPaymentMethod('Invoice'));

        await ShopCustomer.attemptsTo(SubmitOrder());

        const orderId = StorefrontCheckoutFinish.getOrderId();

        TestDataService.addCreatedRecord('order', orderId);

        await test.step('Set the order to "paid", so the customer can access the file.', async () => {
            const orderTransactionId = await getOrderTransactionId(orderId, AdminApiContext);
            const orderTransactionUpdateResponse = await AdminApiContext.post(
                `./_action/order_transaction/${orderTransactionId}/state/paid`,
                {},
            );
            expect(orderTransactionUpdateResponse.ok()).toBeTruthy();
        });

        await test.step('Verify that customer can access the digital product.', async () => {
            // As of 6.8 (feature FLOW_EXECUTION_AFTER_BUSINESS_PROCESS) the "grant download access"
            // flow runs AFTER the paid request instead of inline, so the Download link (rendered
            // only once access is granted) may be absent right after checkout. Reload the order
            // overview and open the details until the link shows up, otherwise the download step
            // times out (see nightly-major failures).
            await ShopCustomer.expects(async () => {
                await ShopCustomer.goesTo(StorefrontAccountOrder.url());
                await ShopCustomer.presses(StorefrontAccountOrder.orderExpandButton);
                await ShopCustomer.expects(StorefrontAccountOrder.digitalProductDownloadButton).toBeVisible();
            }).toPass({ timeout: 30_000 });

            // Reload once more so the download task opens the order details from a clean state.
            await ShopCustomer.goesTo(StorefrontAccountOrder.url());

            // Download the digital product and check if the content is equal to what was uploaded.
            await ShopCustomer.attemptsTo(DownloadDigitalProductFromOrderAndExpectContentToBe(fileContent));
        });
    },
);
